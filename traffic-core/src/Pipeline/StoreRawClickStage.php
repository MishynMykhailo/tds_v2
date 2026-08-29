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
 * — every column not set here keeps its schema DEFAULT.
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

        $pdo = Db::instance();
        $stmt = $pdo->prepare(
            'INSERT INTO clicks (visitor_id, sub_id, datetime, campaign_id, stream_id, landing_id, offer_id, source_id, referrer_id)
             VALUES (:visitor_id, :sub_id, :datetime, :campaign_id, :stream_id, :landing_id, :offer_id, :source_id, :referrer_id)'
        );
        $stmt->execute($payload->rawClick);

        return $payload;
    }
}
