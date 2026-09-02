<?php

namespace TrafficCore\Uniqueness;

use TrafficCore\Redis\RedisClient;

/**
 * Port of legacy `Traffic\Session\Service\UniquenessSessionService` +
 * `Traffic\Session\SessionEntry` (application/Traffic/Session/Service/
 * UniquenessSessionService.php, application/Traffic/Session/
 * SessionEntry.php) — "has this visitor already hit this
 * campaign/stream/anything within the campaign's configured TTL
 * window?".
 *
 * Architectural simplification, not a fidelity cut: legacy stores one
 * JSON blob per uniqueness-id (`campaigns[id]=ts`, `streams[id]=ts`,
 * `time=ts`) in EITHER a response cookie OR Redis/MySQL (`_getSessions()`
 * checks both and ORs the "not unique" verdict — a visitor is only
 * "unique" if BOTH storages agree), gated on `campaign.uniquenessUseCookies`.
 * This port uses ONLY server-side Redis (no cookie storage — traffic-core
 * has no established response-cookie-writing infrastructure to extend
 * for this), which is sufficient for correctness of the uniqueness
 * signal itself (cookie storage in legacy is mainly a client-side
 * cache/fallback when Redis/MySQL is unavailable, not a different source
 * of truth). Each check is a plain Redis `EXISTS` + `SETEX` per
 * dimension instead of one JSON blob with three embedded timestamps —
 * same "is unique within TTL, then mark seen" semantics, idiomatic
 * Redis instead of a hand-rolled expiry check (matches the pattern
 * already established by `HitLimitService`/`LpTokenService` in this
 * project).
 *
 * Uniqueness id: `md5(ip . (isUniqueByIpUa() ? userAgent : ''))` — a
 * literal port of `UniquenessSessionService::getUniquenessId()`. The
 * "deprecated" murmurhash3-based id (`getDeprecatedUniquenessId()`,
 * legacy's own backward-compat fallback for pre-migration sessions) is
 * NOT ported — there is no pre-existing traffic-core install to be
 * backward-compatible with.
 */
class UniquenessService
{
    /**
     * @return array{campaign:bool,stream:bool,global:bool} Whether this
     *         visitor is unique for the campaign/stream/globally, checked
     *         WITHOUT recording this hit yet (see `touch()`).
     */
    public function check(string $ip, string $userAgent, bool $uniqueByIpUa, int $campaignId, ?int $streamId): array
    {
        $id = self::uniquenessId($ip, $userAgent, $uniqueByIpUa);
        $redis = RedisClient::instance();

        $result = [
            'campaign' => !$redis->exists($this->key('campaign', $campaignId, $id)),
            'stream' => $streamId === null ? true : !$redis->exists($this->key('stream', $streamId, $id)),
            'global' => !$redis->exists($this->key('global', 0, $id)),
        ];

        return $result;
    }

    /**
     * Records this hit for the campaign/stream/global dimensions, each
     * with its own TTL reset — call AFTER `check()`, once the click is
     * confirmed not to be a bot (mirrors legacy's `is_bot` short-circuit
     * in `UpdateCampaignUniquenessSessionStage`/
     * `UpdateStreamUniquenessSessionStage`, which skip touching the
     * session for bots entirely).
     */
    public function touch(string $ip, string $userAgent, bool $uniqueByIpUa, int $campaignId, ?int $streamId, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        $id = self::uniquenessId($ip, $userAgent, $uniqueByIpUa);
        $redis = RedisClient::instance();

        $redis->setex($this->key('campaign', $campaignId, $id), $ttlSeconds, '1');
        if ($streamId !== null) {
            $redis->setex($this->key('stream', $streamId, $id), $ttlSeconds, '1');
        }
        $redis->setex($this->key('global', 0, $id), $ttlSeconds, '1');
    }

    /**
     * Public since Phase-13's `EntityBindingService` reuses the exact
     * same id (legacy's `EntityBindingService::findBoundEntity()`/
     * `bindEntityRedis()` call `UniquenessSessionService::
     * getUniquenessId()` too — one shared visitor-identity concept
     * across uniqueness AND sticky entity binding).
     */
    public static function uniquenessId(string $ip, string $userAgent, bool $uniqueByIpUa): string
    {
        return md5($ip . ($uniqueByIpUa ? $userAgent : ''));
    }

    private function key(string $dimension, int $entityId, string $uniquenessId): string
    {
        return "uniq:{$dimension}:{$entityId}:{$uniquenessId}";
    }
}
