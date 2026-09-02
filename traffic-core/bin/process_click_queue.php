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
 *
 * Phase 17 addition: also drains `TrafficCore\Queue\ClickUpdateQueue`
 * (the `update_click` equivalent — `UpdateTokensContext`/
 * `LandingOfferDispatcher::saveLpClick()`) every loop iteration, AFTER
 * inserts, so an update queued in the same tick as its target insert
 * still applies correctly. `sub_id_N` values are resolved through the
 * same `ref_sub_ids` dictionary `BuildRawClickStage` uses (that column
 * is a dictionary FK, not free text — see `clicks` migration);
 * `extra_param_N` are plain string columns, no dictionary lookup needed.
 * A `sub_id` with zero matching rows (update arrived before its insert
 * was processed — see `ClickUpdateQueue`'s docblock) is logged and
 * dropped, no retry.
 */

require __DIR__ . '/../vendor/autoload.php';

use TrafficCore\Db;
use TrafficCore\Pipeline\Visitor\DictionaryRepository;
use TrafficCore\Queue\ClickQueue;
use TrafficCore\Queue\ClickUpdateQueue;

const POLL_INTERVAL_SECONDS = 1;
const SUB_ID_COUNT = 15;

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

function applyUpdate(\PDO $pdo, DictionaryRepository $dictionaries, array $fields): bool
{
    $subId = (string) $fields['sub_id'];
    unset($fields['sub_id']);

    $set = [];
    $values = [];

    for ($i = 1; $i <= SUB_ID_COUNT; $i++) {
        $key = "sub_id_{$i}";
        if (array_key_exists($key, $fields)) {
            $set[] = "{$key}_id = ?";
            $values[] = $dictionaries->findOrCreateByValue('ref_sub_ids', $fields[$key]);
            unset($fields[$key]);
        }
    }

    if (array_key_exists('offer_id', $fields)) {
        $set[] = 'offer_id = ?';
        $values[] = $fields['offer_id'];

        $stmt = $pdo->prepare('SELECT affiliate_network_id FROM offers WHERE id = ? LIMIT 1');
        $stmt->execute([$fields['offer_id']]);
        $affiliateNetworkId = $stmt->fetchColumn();
        if ($affiliateNetworkId !== false) {
            $set[] = 'affiliate_network_id = ?';
            $values[] = $affiliateNetworkId;
        }

        unset($fields['offer_id']);
    }

    if (array_key_exists('landing_clicked', $fields)) {
        $set[] = 'landing_clicked = 1';
        $set[] = 'landing_clicked_datetime = ?';
        $values[] = $fields['landing_clicked'];
        unset($fields['landing_clicked']);
    }

    if (array_key_exists('is_bot', $fields)) {
        $set[] = 'is_bot = ?';
        $values[] = $fields['is_bot'];
        unset($fields['is_bot']);
    }

    // Remaining keys (extra_param_N) are plain string columns.
    foreach ($fields as $key => $value) {
        $set[] = "{$key} = ?";
        $values[] = $value;
    }

    if ($set === []) {
        return true;
    }

    $values[] = $subId;
    $stmt = $pdo->prepare('UPDATE clicks SET ' . implode(', ', $set) . ' WHERE sub_id = ?');
    $stmt->execute($values);

    return $stmt->rowCount() > 0;
}

function processUpdateBatch(\PDO $pdo, DictionaryRepository $dictionaries, array $batch): int
{
    $applied = 0;
    foreach ($batch as $fields) {
        if (applyUpdate($pdo, $dictionaries, $fields)) {
            $applied++;
        } else {
            fwrite(STDERR, "[click-queue-worker] update for sub_id '{$fields['sub_id']}' matched no click, dropped\n");
        }
    }

    return $applied;
}

$queue = new ClickQueue();
$updateQueue = new ClickUpdateQueue();
$dictionaries = new DictionaryRepository();
$pdo = Db::instance();

fwrite(STDOUT, "[click-queue-worker] started\n");

while (true) {
    $batch = $queue->pop();
    $updateBatch = $updateQueue->pop();

    if ($batch === [] && $updateBatch === []) {
        sleep(POLL_INTERVAL_SECONDS);
        continue;
    }

    if ($batch !== []) {
        try {
            $inserted = processBatch($pdo, $batch);
            fwrite(STDOUT, "[click-queue-worker] inserted {$inserted} click(s)\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, '[click-queue-worker] batch insert failed: ' . $e->getMessage() . "\n");
        }
    }

    if ($updateBatch !== []) {
        try {
            $applied = processUpdateBatch($pdo, $dictionaries, $updateBatch);
            fwrite(STDOUT, "[click-queue-worker] updated {$applied} click(s)\n");
        } catch (\Throwable $e) {
            fwrite(STDERR, '[click-queue-worker] batch update failed: ' . $e->getMessage() . "\n");
        }
    }
}
