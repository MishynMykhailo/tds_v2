<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;
use TrafficCore\Pipeline\Filters\FilterEngine;

/**
 * Port of legacy `Component\StreamFilters\CheckFilters` (Phase 4 —
 * replaces Phase 2's whole-stream fail-open with real per-filter
 * evaluation for the implemented filter types; see
 * docs/TRAFFIC_CORE_PLAN.md "Фаза 4").
 *
 * Combining logic ported 1-to-1 from legacy `CheckFilters::isPass()`:
 * AND semantics by default (`stream.filter_or = 0`) — any failing filter
 * stops immediately and returns false, no failure means overall pass; OR
 * semantics (`stream.filter_or = 1`) — any passing filter stops
 * immediately and returns true, no pass means overall fail.
 *
 * Per-filter fail-open (NOT whole-stream, unlike Phase 2): a filter
 * `name` with no `FilterEngine::evaluate()` implementation (geo/device
 * dictionary filters (country/region/city/isp/operator/os/browser/
 * device_type/device_model/connection_type)/uniqueness/imklo/
 * hide_click — see plan doc; `bot`/`proxy` used to be on this list too,
 * both are real now) is treated as
 * passed for THAT filter only, logged via error_log() and surfaced in
 * `X-Filters-Skipped` as `stream#N:name1,name2`.
 *
 * `limit` is now real (hit-limit enforcement, `FilterEngine::limit()` /
 * `TrafficCore\HitLimit\HitLimitService`) — removed from the fail-open
 * list above; `FilterEngine::evaluate()` needs to know which stream is
 * being checked to build its Redis key (`rate:<stream_id>`), so this
 * class's one call site below passes `(int) $stream['id']` as a new
 * trailing parameter — see `FilterEngine::evaluate()`'s own docblock for
 * why this approach was chosen over the alternatives.
 *
 * `bot` is now real too (`BotDetection\BotDetectionService` via
 * `Payload::$isBot`, resolved by `ResolveVisitorStage` before
 * `ChooseStreamStage` runs) — also removed from the fail-open list;
 * threaded through the same way as `$streamId`, as this class's
 * `isPass()` new trailing `bool $isBot` parameter. `proxy` is real too
 * now (`Pipeline\Proxy\ProxyDetectionResolver` via `Payload::
 * $isUsingProxy`, same resolution point and reason as `$isBot`) —
 * threaded through as a further trailing `bool $isUsingProxy` parameter.
 */
class CheckFilters
{
    /** @var list<string> "stream#N:name1,name2" entries, surfaced via X-Filters-Skipped. */
    public static array $skipped = [];

    /** @param array<string,mixed> $signal see Signal::fromRequest() */
    public static function isPass(array $stream, array $signal, bool $isBot = false, bool $isUsingProxy = false): bool
    {
        $rows = self::loadFilters((int) $stream['id']);

        if (empty($rows)) {
            return true;
        }

        $filterOr = (bool) $stream['filter_or'];
        $skippedNames = [];

        foreach ($rows as $row) {
            $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : $row['payload'];
            $passed = FilterEngine::evaluate($row['name'], $row['mode'], $payload, $signal, (int) $stream['id'], $isBot, $isUsingProxy);

            if ($passed === null) {
                $skippedNames[] = $row['name'];
                $passed = true;
            }

            if (! $passed) {
                if (! $filterOr) {
                    self::recordSkipped((int) $stream['id'], $skippedNames);

                    return false;
                }
            } else {
                if ($filterOr) {
                    self::recordSkipped((int) $stream['id'], $skippedNames);

                    return true;
                }
            }
        }

        self::recordSkipped((int) $stream['id'], $skippedNames);

        // AND: reached the end with no failure -> pass. OR: reached the
        // end with no pass -> fail.
        return ! $filterOr;
    }

    /**
     * @return list<array{name:string,mode:string,payload:mixed}>
     */
    private static function loadFilters(int $streamId): array
    {
        $stmt = Db::instance()->prepare('SELECT name, mode, payload FROM stream_filters WHERE stream_id = ?');
        $stmt->execute([$streamId]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function recordSkipped(int $streamId, array $names): void
    {
        if (empty($names)) {
            return;
        }

        $msg = "[CheckFilters] stream #{$streamId} has unimplemented filter type(s): "
             . implode(',', $names) . ' — passing through (fail-open per filter, not per stream)';
        error_log($msg);
        self::$skipped[] = "stream#{$streamId}:" . implode(',', $names);
    }
}
