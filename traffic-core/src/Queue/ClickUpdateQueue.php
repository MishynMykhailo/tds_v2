<?php

namespace TrafficCore\Queue;

use TrafficCore\Redis\RedisClient;

/**
 * Port of legacy's `update_click` delayed command (`Traffic\Command\
 * DelayedCommand\UpdateClickCommand::updateTokens()`/`saveLpClick()`,
 * application/Traffic/Command/UpdateClickCommand.php) — a post-hoc
 * `UPDATE clicks SET ... WHERE sub_id = ?` for data a landing page's own
 * JS reports back after the initial click already happened
 * (`UpdateTokensContext`) or after an offer was actually shown
 * (`LandingOfferDispatcher`'s `saveLpClick`).
 *
 * A SEPARATE Redis list from `ClickQueue` (`click_queue`, the INSERT
 * queue from Phase 15) rather than one combined queue: legacy pushes
 * both `add_click` and `update_click` onto the same generic
 * `DelayedCommandService` FIFO queue and relies on strict processing
 * order for `update_click` to always land after the `add_click` it
 * targets. Two separate lists drained by the SAME worker loop
 * (`bin/process_click_queue.php`, inserts-then-updates each iteration)
 * are simpler to reason about here and carry the same practical
 * ordering guarantee in this project's actual traffic pattern: an update
 * can only be triggered by a landing page or a landing-offer request,
 * both of which require the visitor to have already been redirected by
 * the original click — i.e. several page-load-times after the insert was
 * queued, virtually always longer than the worker's own ~1s poll
 * interval. A `sub_id` that genuinely hasn't been inserted yet when its
 * update is drained is dropped with a log line by the worker (no retry —
 * same no-retry stance as `ClickQueue`'s own docblock documents for
 * `add_click`), not silently misapplied to the wrong row.
 */
class ClickUpdateQueue
{
    private const QUEUE_KEY = 'click_update_queue';
    private const BATCH_SIZE = 1000;

    /** @param array<string,mixed> $fields Must include 'sub_id'. */
    public function push(array $fields): void
    {
        RedisClient::instance()->rpush(self::QUEUE_KEY, (string) json_encode($fields));
    }

    /** @return list<array<string,mixed>> */
    public function pop(): array
    {
        $redis = RedisClient::instance();

        [$items] = $redis->pipeline(function ($pipe) {
            $pipe->lrange(self::QUEUE_KEY, 0, self::BATCH_SIZE - 1);
            $pipe->ltrim(self::QUEUE_KEY, self::BATCH_SIZE, -1);
        });

        $result = [];
        foreach ($items as $encoded) {
            $decoded = json_decode((string) $encoded, true);
            if (is_array($decoded) && !empty($decoded['sub_id'])) {
                $result[] = $decoded;
            }
        }

        return $result;
    }

    public function count(): int
    {
        return (int) RedisClient::instance()->llen(self::QUEUE_KEY);
    }
}
