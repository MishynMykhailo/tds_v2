<?php

namespace TrafficCore\Postback;

use TrafficCore\Db;

/**
 * Port of the click-lookup half of legacy
 * `Component\Clicks\Repository\ClickRepository::findByPostback()`
 * (application/Component/Clicks/Repository/ClickRepository.php).
 *
 * SCOPED DOWN per task: legacy also resolves `Component\Clicks\Model\
 * ClickLink` rows (`parent_sub_id`/`sub_id` chains — used when a click
 * gets "linked" to another sub_id, e.g. a landing-page click-through)
 * and returns every click sharing that link chain. This project has no
 * `ClickLink`-equivalent table/feature yet, so this is a direct
 * `SELECT * FROM clicks WHERE sub_id = ?` — the exact simplification the
 * task calls for.
 */
class PostbackClickRepository
{
    /** @return array<string,mixed>|null */
    public function findBySubId(string $subId): ?array
    {
        $stmt = Db::instance()->prepare('SELECT * FROM clicks WHERE sub_id = ? LIMIT 1');
        $stmt->execute([$subId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /** @param array<string,mixed> $fields */
    public function update(int $clickId, array $fields): void
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
        $values[] = $clickId;

        $sql = 'UPDATE clicks SET ' . implode(', ', $set) . ' WHERE click_id = ?';
        Db::instance()->prepare($sql)->execute($values);
    }
}
