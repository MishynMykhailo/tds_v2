<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Queue\ClickQueue;

/**
 * Merged, trimmed port of legacy `PrepareRawClickToStoreStage` +
 * `StoreRawClicksStage` (application/Traffic/Pipeline/Stage/
 * PrepareRawClickToStoreStage.php, StoreRawClicksStage.php).
 *
 * Phase 15: async for real now — matches legacy's actual behavior
 * (`Component\Clicks\DelayedCommand\AddClickCommand::saveClick()` pushes
 * onto a Redis-backed command queue instead of inserting synchronously,
 * for click-path throughput). This stage now only `ClickQueue::push()`s
 * `payload->rawClick` (a plain Redis `RPUSH`, sub-millisecond) — the
 * actual `INSERT` happens in `bin/process_click_queue.php`, a separate
 * worker process/container (see `deploy/docker-compose.yml`'s
 * `traffic-core-worker` service). Until Phase 15, this stage did the
 * `INSERT` directly inline — see git history for that version if a
 * synchronous fallback is ever needed again.
 *
 * NOT ported: legacy's `collect_clicks` stream flag / `disable_stats`
 * setting checks (both gate whether a click is queued at all) — every
 * click that reaches this stage is queued unconditionally.
 */
class StoreRawClickStage
{
    public function process(Payload $payload): Payload
    {
        if ($payload->aborted || empty($payload->rawClick)) {
            return $payload;
        }

        (new ClickQueue())->push($payload->rawClick);

        return $payload;
    }
}
