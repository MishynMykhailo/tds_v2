<?php

/**
 * Click-queue worker — Phase 15. Consumes `TrafficCore\Queue\ClickQueue`
 * (a Redis list `StoreRawClickStage` now `RPUSH`es onto instead of
 * inserting synchronously) and batch-inserts into `clicks`. Port of
 * legacy `Component\DelayedCommands\Processor\ProcessCommandQueue` +
 * `Component\Clicks\DelayedCommand\AddClickCommand::process()` (which
 * hands the popped batch to `Component\Clicks\ClickProcessing\Pipeline`
 * -> `SaveClicks` -> `Core\Db\Db::multiInsert()`), reduced to just the
 * `add_click` command type (traffic-core's queue is click-only, see
 * `ClickQueue`'s own docblock).
 *
 * Grouping logic ported literally from `Db::multiInsert()`
 * (application/Core/Db/Db.php:169, read directly this session): rows in
 * one popped batch are expected to share identical `rawClick` keys
 * (they always do here — `BuildRawClickStage` always produces the same
 * 35 fields), but if a batch ever contains rows with a DIFFERENT key
 * set, legacy flushes the accumulated group as one multi-row INSERT and
 * starts a new group for the differing shape rather than failing or
 * silently dropping columns — ported the same way here, not simplified
 * away.
 *
 * Run continuously, e.g. `deploy/docker-compose.yml`'s
 * `traffic-core-worker` service (`php bin/process_click_queue.php`) —
 * long-running, polls `ClickQueue::pop()` in a loop with a short sleep
 * when the queue is empty. Not a legacy-parity concern (legacy's own
 * consumer is cron-driven, this is a persistent loop instead) — either
 * shape satisfies "the INSERT happens off the click's own request",
 * which is the actual throughput goal.
 */

require __DIR__ . '/../vendor/autoload.php';

use TrafficCore\Db;
use TrafficCore\Queue\ClickQueue;

const POLL_INTERVAL_SECONDS = 1;

function insertGroup(\PDO $pdo, array $columns, array $rows): void
{
    if ($rows === []) {
        return;
    }

    $placeholders = '(' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $sql = 'INSERT INTO clicks (' . implode(', ', $columns) . ') VALUES '
        . implode(', ', array_fill(0, count($rows), $placeholders));

    $values = [];
    foreach ($rows as $row) {
        foreach ($columns as $column) {
            $values[] = $row[$column] ?? null;
        }
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function processBatch(\PDO $pdo, array $batch): int
{
    $inserted = 0;
    $groupColumns = null;
    $groupRows = [];

    foreach ($batch as $row) {
        $columns = array_keys($row);
        sort($columns);

        if ($groupColumns !== null && $columns !== $groupColumns) {
            insertGroup($pdo, $groupColumns, $groupRows);
            $inserted += count($groupRows);
            $groupRows = [];
        }

        $groupColumns = $columns;
        $groupRows[] = $row;
    }

    if ($groupRows !== []) {
        insertGroup($pdo, $groupColumns, $groupRows);
        $inserted += count($groupRows);
    }

    return $inserted;
}

$queue = new ClickQueue();
$pdo = Db::instance();

fwrite(STDOUT, "[click-queue-worker] started\n");

while (true) {
    $batch = $queue->pop();

    if ($batch === []) {
        sleep(POLL_INTERVAL_SECONDS);
        continue;
    }

    try {
        $inserted = processBatch($pdo, $batch);
        fwrite(STDOUT, "[click-queue-worker] inserted {$inserted} click(s)\n");
    } catch (\Throwable $e) {
        fwrite(STDERR, '[click-queue-worker] batch insert failed: ' . $e->getMessage() . "\n");
    }
}
