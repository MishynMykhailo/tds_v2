<?php

namespace TrafficCore\Uniqueness;

use TrafficCore\Redis\RedisClient;

/**
 * Port of legacy `Traffic\Pipeline\Service\EntityBindingService`
 * (application/Traffic/Pipeline/Service/EntityBindingService.php) — the
 * "sticky selection" half of the visitor/uniqueness cluster: once a
 * visitor lands on a particular stream/landing/offer, remember that
 * choice (keyed by the same uniqueness id `UniquenessService` uses) so
 * a repeat visit within the campaign's `cookies_ttl` window gets the
 * SAME one instead of a fresh weighted roll — used by `StreamRotator`/
 * `LandingOfferRotator`.
 *
 * Simplification, not a fidelity cut: legacy checks Redis first, then a
 * "deprecated" murmurhash3 id, then falls back to reading a signed
 * cookie (`findBoundEntity()`), and separately writes BOTH a Redis key
 * and (from `SetCookieStage`, unported) a cookie (`bindEntityCookies()`).
 * This port is Redis-only — same reasoning as `UniquenessService`'s
 * docblock (no established response-cookie-writing infra here, and
 * Redis alone is sufficient for server-side correctness).
 *
 * Legacy also stores a "previous IP" alongside the bound entity purely
 * for log messages (`_logMessage()`'s "(previous IP was ...)" — not
 * consumed by any decision logic anywhere) — NOT ported, traffic-core
 * has no per-request log sink to write it to anyway (same gap as
 * everywhere else in this port).
 */
class EntityBindingService
{
    public const TYPE_STREAM = 'stream';
    public const TYPE_LANDING = 'landing';
    public const TYPE_OFFER = 'offer';

    /**
     * Legacy's `findBoundEntity()` — returns the bound entity id, or
     * null if none is bound (or Redis has nothing for this
     * visitor/type/campaign combination).
     */
    public function find(string $type, int $campaignId, string $ip, string $userAgent, bool $uniqueByIpUa): ?int
    {
        $id = UniquenessService::uniquenessId($ip, $userAgent, $uniqueByIpUa);
        $value = RedisClient::instance()->get($this->key($type, $campaignId, $id));

        return $value !== null ? (int) $value : null;
    }

    /**
     * Legacy's `bindEntityRedis()` — remembers this choice for
     * `$ttlSeconds` (campaign's `cookies_ttl` in seconds, same TTL
     * source as `UniquenessService::touch()`).
     */
    public function bind(string $type, int $campaignId, string $ip, string $userAgent, bool $uniqueByIpUa, int $entityId, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            return;
        }

        $id = UniquenessService::uniquenessId($ip, $userAgent, $uniqueByIpUa);
        RedisClient::instance()->setex($this->key($type, $campaignId, $id), $ttlSeconds, (string) $entityId);
    }

    private function key(string $type, int $campaignId, string $uniquenessId): string
    {
        return "bind:{$type}:{$campaignId}:{$uniquenessId}";
    }
}
