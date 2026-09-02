<?php

namespace TrafficCore\LpToken;

use TrafficCore\Db;
use TrafficCore\Redis\RedisClient;

/**
 * Port of the `storeRawClick()`/TTL half of legacy
 * `Traffic\LpToken\Service\LpTokenService`
 * (application/Traffic/LpToken/Service/LpTokenService.php) — the
 * offer-redirect two-step tracking/attribution token flow. This is a
 * DIFFERENT mechanism from `TrafficCore\LpToken\LpTokenKey` (that one is
 * a JWT signing key for `double_meta`'s two-step redirect — see its own
 * docblock for the "these are unrelated" note this class confirms from
 * the other side).
 *
 * `storeRawClick()`: token format ported literally —
 * `self::UUID_PREFIX . $subId . "_" . uniqid($subId, true)`,
 * `UUID_PREFIX = "uuid_"` (application/Traffic/LpToken/Service/
 * LpTokenService.php `_generateToken()`). The click payload is
 * JSON-encoded and stored via Redis `SETEX` under a TTL — see
 * `RedisClient`.
 *
 * NOT ported: legacy's `Tools::utf8ize()` call before `json_encode()` —
 * unneeded here because traffic-core's `Payload::$rawClick` is already a
 * plain associative array of scalars (int/string/null columns bound for
 * a `clicks` INSERT — see `BuildRawClickStage`), not legacy's richer
 * `RawClick` object graph that `utf8ize()` was guarding against invalid
 * byte sequences in. Confirmed by reading `BuildRawClickStage::process()`
 * — every value it puts in `rawClick` is already a plain scalar.
 *
 * NOT ported: legacy `RedisStorage`'s own key-namespacing
 * (`RW_CLCS:<5-char-salt-hash>:<token>`, see application/Traffic/LpToken/
 * Storage/RedisStorage.php `_buildKey()`) and its optional gzip
 * compression layer. The task this class was written for explicitly
 * calls for the token itself to be the literal Redis key (so a postback/
 * conversion callback — not yet built anywhere in this project — can do
 * a direct `GET <token>` without knowing an install-specific prefix), so
 * `_buildKey()`'s legacy prefixing is a deliberate, documented deviation,
 * not an oversight.
 *
 * TTL: `getTtlSeconds()` mirrors legacy `LpTokenService::getTTL()`
 * (`intval(setting('lp_offer_token_ttl', 1440)) * 60` — the setting value
 * is stored in MINUTES, default 1440 = 24h) — reads the `settings` table
 * the same way `TrafficCore\Pipeline\Actions\LocalFileSandbox`'s private
 * `setting()` does (`SELECT value FROM settings WHERE \`key\` = ?`).
 */
class LpTokenService
{
    private const UUID_PREFIX = 'uuid_';

    /** Legacy `LpTokenService::DEFAULT_TTL` (seconds) — application/Traffic/LpToken/Service/LpTokenService.php. */
    private const DEFAULT_TTL_SECONDS = 86400;

    /** Legacy setting key — application/Traffic/Model/Setting.php `LP_OFFER_TOKEN_TTL`. */
    private const TTL_SETTING_KEY = 'lp_offer_token_ttl';

    /**
     * @param array<string,mixed> $rawClick
     */
    public function storeRawClick(array $rawClick, int $ttlSeconds): string
    {
        $subId = (string) ($rawClick['sub_id'] ?? '');
        $token = $this->generateToken($subId);

        $encoded = json_encode($rawClick, JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($encoded === false) {
            $encoded = '{}';
        }

        RedisClient::instance()->setex($token, max(1, $ttlSeconds), $encoded);

        return $token;
    }

    public function getTtlSeconds(): int
    {
        $ttl = (int) ($this->setting(self::TTL_SETTING_KEY) ?? (self::DEFAULT_TTL_SECONDS / 60)) * 60;

        return $ttl > 0 ? $ttl : self::DEFAULT_TTL_SECONDS;
    }

    private function generateToken(string $subId): string
    {
        return self::UUID_PREFIX . $subId . '_' . uniqid($subId, true);
    }

    private function setting(string $key): ?string
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
