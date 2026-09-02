<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Merged, trimmed port of legacy `PrepareRawClickToStoreStage` +
 * `StoreRawClicksStage` (application/Traffic/Pipeline/Stage/
 * PrepareRawClickToStoreStage.php, StoreRawClicksStage.php), which in
 * legacy queue the click via `Component\Clicks\DelayedCommand\
 * AddClickCommand::saveClick()`.
 *
 * Ported: a synchronous raw PDO INSERT into the existing `clicks` table
 * (backend/database/migrations/2025_01_01_000018_create_clicks_table.php)
 * — every column not set here keeps its schema DEFAULT. Column list is
 * built from `array_keys($payload->rawClick)` rather than hand-written
 * (35 fields as of `BuildRawClickStage`'s full port — source_id/
 * referrer_id/search_engine_id/keyword_id/ad_campaign_id_id/
 * x_requested_with_id/creative_id_id/external_id_id/15 sub_id_N_id/10
 * extra_param_N, plus the original 8) — safe because `rawClick`'s keys
 * are always this project's own fixed literal field names, never
 * request-controlled strings.
 *
 * NOT ported: legacy's `collect_clicks` stream flag / `disable_stats`
 * setting checks (both gate whether a click is stored at all), and the
 * async delayed-command queueing (legacy defers the actual INSERT to a
 * background command for throughput — here it's synchronous, fine for a
 * Phase-1 proof of concept, revisit once real traffic volume is a
 * concern).
 */
class StoreRawClickStage
{
    public function process(Payload $payload): Payload
    {
        if ($payload->aborted || empty($payload->rawClick)) {
            return $payload;
        }

        $columns = array_keys($payload->rawClick);
        $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

        $sql = 'INSERT INTO clicks (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = Db::instance()->prepare($sql);
        $stmt->execute($payload->rawClick);

        return $payload;
    }
}
