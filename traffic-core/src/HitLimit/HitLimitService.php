<?php

namespace TrafficCore\HitLimit;

use TrafficCore\Redis\RedisClient;

/**
 * Port of legacy `Traffic\HitLimit\Storage\RedisStorage`
 * (application/Traffic/HitLimit/Storage/RedisStorage.php), merged with
 * the pass-through wrapper `Traffic\HitLimit\Service\HitLimitService`
 * (application/Traffic/HitLimit/Service/HitLimitService.php — legacy
 * has a storage-interface indirection with only one real implementation
 * ever wired in via `Factory.php`; collapsed here into one class since
 * traffic-core has exactly one backend, same simplification style as
 * this project's `RedisClient`/`Db` singletons).
 *
 * Mechanism ported 1-to-1: one Redis sorted set per stream, key
 * `rate:<stream_id>` (`SET_PREFIX`), member = a unique-per-call string
 * (content doesn't matter, only needs to be unique so `ZADD` doesn't
 * collide/overwrite), score = the click's own Unix timestamp.
 * `perHour`/`perDay` are `ZCOUNT <key> <now-3600|now-86400> +inf`,
 * `total` is `ZCOUNT <key> -inf +inf` — exact legacy ranges.
 *
 * Takes a plain `int $streamId` + `int $timestamp` instead of legacy's
 * `\Traffic\Model\BaseStream`/`\DateTime` objects — traffic-core's
 * pipeline stages already work with plain arrays/scalars (see
 * `Payload::$stream`), no stream *model* object exists here.
 *
 * NOT ported: `prune()` (cron-driven `ZREMRANGEBYSCORE` cleanup —
 * separate infra, explicitly out of scope for live-check correctness
 * per this task) and the `rate_collection` Redis SET that legacy's
 * `store()` also writes via `sAdd()` — confirmed by
 * `grep -rn "rate_collection" application/` that nothing anywhere reads
 * that set except `store()`'s own write; `prune()`'s exception list
 * (`_getStreamIdsWithLimitTotal()`) queries `StreamFilterRepository`
 * directly instead, never the collection set. Dead write in legacy,
 * not ported.
 *
 * Member format: legacy `_getRand()` is `date("YmdHis") . rand(10000,
 * 999999)`. Ported semantically (uniqueness is all that matters, per
 * this task's own spec) using `random_int()` instead of `rand()` — a
 * CSPRNG is strictly better here and changes nothing observable (the
 * member string is never read back, only used as a unique ZSET member).
 */
class HitLimitService
{
    private const SET_PREFIX = 'rate:';

    public function store(int $streamId, int $timestamp): void
    {
        $member = date('YmdHis') . random_int(10000, 999999);
        RedisClient::instance()->zadd($this->setName($streamId), [$member => $timestamp]);
    }

    public function perHour(int $streamId, int $timestamp): int
    {
        return (int) RedisClient::instance()->zcount($this->setName($streamId), $timestamp - 3600, '+inf');
    }

    public function perDay(int $streamId, int $timestamp): int
    {
        return (int) RedisClient::instance()->zcount($this->setName($streamId), $timestamp - 86400, '+inf');
    }

    public function total(int $streamId): int
    {
        return (int) RedisClient::instance()->zcount($this->setName($streamId), '-inf', '+inf');
    }

    private function setName(int $streamId): string
    {
        return self::SET_PREFIX . $streamId;
    }
}
