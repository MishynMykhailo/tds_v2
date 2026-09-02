<?php

namespace TrafficCore\Postback;

use TrafficCore\Db;

/**
 * Find-or-update-by-sub_id conversion storage for the postback flow.
 *
 * SCOPE (per task, "you do NOT need to port the full generality"): legacy
 * `Component\Postback\ProcessPostback\PayloadFactory::produce()` +
 * `Payload::updateOldConversionsTid()`/`isStatusChange()`/
 * `isNewConversion()`/`isAdditionalConversion()` (application/Component/
 * Postback/ProcessPostback/Payload.php) support MULTIPLE conversion rows
 * per sub_id (rebills, upsells, additional non-matching-tid conversions),
 * matched by a PHP loose `==` comparison between the incoming postback's
 * tid and EACH existing conversion's tid.
 *
 * This port explicitly SKIPS multi-conversion-per-subid (task: "explicitly
 * SKIP: ... is_processed/multi-conversion-per-subid beyond the tid-dedup
 * case above"). Since `clicks.sub_id` is UNIQUE in this schema (exactly
 * one click per sub_id — confirmed via `DESCRIBE clicks`), this repository
 * collapses legacy's tid-matching dedup logic to a simpler, stricter
 * invariant: **at most one `conversions` row per sub_id, period.** The
 * first postback for a sub_id inserts; every later postback for that same
 * sub_id (whether or not it carries a tid, whether or not that tid
 * matches a previous one) updates that same row in place. This still
 * satisfies the task's literal dedup example (a repeat postback for the
 * same tid updates in place, not a duplicate insert) as its most common
 * realization, while avoiding the added complexity of legacy's
 * multi-conversion bookkeeping, which is explicitly out of scope.
 */
class PostbackConversionRepository
{
    /** @return array<string,mixed>|null */
    public function findBySubId(string $subId): ?array
    {
        $stmt = Db::instance()->prepare('SELECT * FROM conversions WHERE sub_id = ? LIMIT 1');
        $stmt->execute([$subId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * @param array<string,mixed> $fields
     */
    public function insert(array $fields): int
    {
        $columns = array_keys($fields);
        $placeholders = array_fill(0, count($columns), '?');

        $sql = 'INSERT INTO conversions (' . implode(', ', array_map(fn ($c) => "`{$c}`", $columns)) . ')
                VALUES (' . implode(', ', $placeholders) . ')';

        $stmt = Db::instance()->prepare($sql);
        $stmt->execute(array_values($fields));

        return (int) Db::instance()->lastInsertId();
    }

    /** @param array<string,mixed> $fields */
    public function update(int $conversionId, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $set = [];
        $values = [];
        foreach ($fields as $column => $value) {
            $set[] = "`{$column}` = ?";
            $values[] = $value;
        }
        $values[] = $conversionId;

        $sql = 'UPDATE conversions SET ' . implode(', ', $set) . ' WHERE conversion_id = ?';
        Db::instance()->prepare($sql)->execute($values);
    }
}
