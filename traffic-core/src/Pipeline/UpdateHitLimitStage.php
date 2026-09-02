<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;
use TrafficCore\HitLimit\HitLimitService;

/**
 * Port of legacy `Traffic\Pipeline\Stage\UpdateHitLimitStage`
 * (application/Traffic/Pipeline/Stage/UpdateHitLimitStage.php) — records
 * one hit in the Redis-backed hit-counter (`HitLimitService`) for the
 * chosen stream, but ONLY if that stream has a `limit`-type filter
 * attached at all (any config, even an empty/misconfigured one — legacy
 * `hasLimitFilter()` just checks filter *name*, not payload content).
 *
 * This recording must happen regardless of whether the click's action
 * later redirects successfully — hence it runs BEFORE `ExecuteActionStage`
 * in `public/index.php`'s pipeline array (own hit-recording is
 * unconditional once this stage runs, not deferred to after the
 * response), matching legacy's real stage order relative to
 * `ExecuteActionStage` (see index.php's docblock: legacy runs
 * `UpdateHitLimitStage` well before `ExecuteActionStage`, right after
 * `GenerateTokenStage`/`FindAffiliateNetworkStage`).
 *
 * `hasLimitFilter()` ported as a direct `stream_filters` query (legacy's
 * `CachedStreamFilterRepository::instance()->allCached($stream)` — no
 * cache layer here, same as every other filter lookup in this project,
 * see `CheckFilters::loadFilters()`).
 *
 * Timestamp source: legacy passes `$rawClick->getDateTime()` (the click's
 * own recorded time) to `store()`. Here that's `$payload->rawClick
 * ['datetime']` (set by `BuildRawClickStage` as `gmdate('Y-m-d H:i:s')`,
 * UTC) parsed back to a Unix timestamp — same field the click record
 * itself uses, not a fresh `time()` call, so hit-limit counting is
 * consistent with what actually gets stored for this click.
 */
class UpdateHitLimitStage
{
    private const FILTER_NAME = 'limit';

    public function process(Payload $payload): Payload
    {
        if (empty($payload->rawClick) || $payload->stream === null) {
            return $payload;
        }

        $streamId = (int) $payload->stream['id'];

        if (!$this->hasLimitFilter($streamId)) {
            return $payload;
        }

        $timestamp = $this->clickTimestamp($payload->rawClick['datetime'] ?? null);

        (new HitLimitService())->store($streamId, $timestamp);

        return $payload;
    }

    private function hasLimitFilter(int $streamId): bool
    {
        $stmt = Db::instance()->prepare('SELECT 1 FROM stream_filters WHERE stream_id = ? AND name = ? LIMIT 1');
        $stmt->execute([$streamId, self::FILTER_NAME]);

        return (bool) $stmt->fetchColumn();
    }

    private function clickTimestamp(?string $datetime): int
    {
        if ($datetime === null) {
            return time();
        }

        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $datetime, new \DateTimeZone('UTC'));

        return $dt !== false ? $dt->getTimestamp() : time();
    }
}
