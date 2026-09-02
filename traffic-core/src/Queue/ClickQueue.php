<?php

namespace TrafficCore\Queue;

use TrafficCore\Redis\RedisClient;

/**
 * Port of legacy `Traffic\CommandQueue\QueueStorage\RedisStorage` +
 * `Traffic\CommandQueue\Service\DelayedCommandService`
 * (application/Traffic/CommandQueue/QueueStorage/RedisStorage.php,
 * application/Traffic/CommandQueue/Service/DelayedCommandService.php) —
 * a plain Redis LIST used as a queue: `RPUSH` to enqueue, an atomic
 * `LRANGE`+`LTRIM` pipeline to dequeue a batch (never `LPOP` in a loop —
 * legacy's exact reason for the range+trim pipeline is to pull up to
 * `RANGE_SIZE` items in one round trip instead of one Redis call per
 * item, still ported here even though this project's expected queue
 * depth is far smaller than legacy's real production traffic).
 *
 * Scoped to `clicks` only (legacy's queue is generic — ANY delayed
 * command, `add_click` being just one of several `COMMAND` names it
 * carries). traffic-core has no other delayed-command consumers, so a
 * single-purpose queue class is simpler than porting the generic
 * command-dispatch layer for one command type.
 *
 * NOT ported: gzip compression (`enableCompression()` — legacy's own
 * queue entries are small JSON objects, same here, not worth the
 * complexity), the "additional Redis queue" mirroring
 * (`ADDITIONAL_REDIS_QUEUE` setting — a legacy multi-tenant/migration
 * feature, not relevant here), retry/dead-letter logic
 * (`isRetryAvailable()`/`retry()` — a failed batch insert here is
 * logged and the batch is simply lost, same as legacy's
 * `CommandAggregator::flushAll()` having no retry path of its own
 * either for the `add_click` command specifically).
 */
class ClickQueue
{
    private const QUEUE_KEY = 'click_queue';
    private const BATCH_SIZE = 1000;

    /** @param array<string,mixed> $rawClick */
    public function push(array $rawClick): void
    {
        RedisClient::instance()->rpush(self::QUEUE_KEY, (string) json_encode($rawClick));
    }

    /**
     * Atomically pulls up to `BATCH_SIZE` queued clicks and removes them
     * from the queue in the same round trip (`MULTI`/`LRANGE`+`LTRIM`/
     * `EXEC` — a pipeline, not a transaction in the "rollback on
     * failure" sense, but Redis executes a pipeline's commands
     * uninterrupted by other clients, so no click can be popped twice).
     *
     * @return list<array<string,mixed>>
     */
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
            if (is_array($decoded)) {
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
