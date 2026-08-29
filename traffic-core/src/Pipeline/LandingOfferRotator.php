<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Actions\LandingOfferRotator`
 * (application/Traffic/Pipeline/Rotator/LandingOfferRotator.php) —
 * Phase 3. One class serves both landing and offer rotation in legacy
 * (parameterized by binding-entity type); here parameterized by the
 * target table name (`landings`/`offers`) and the association's FK
 * column name (`landing_id`/`offer_id`) — both always literal strings
 * from our own calling code, never request-derived, so no injection risk
 * from the unparameterized table name in the SQL below.
 *
 * Ported: `getRandom()` (recursively skip associations whose resolved
 * entity is missing/not `state=active`) and `_rollDice()` (legacy's exact
 * algorithm: shuffle, then for each item roll `mt_rand(0, totalWeight +
 * share)` and keep it as `$selected` while `totalWeight <= rand`,
 * accumulating `totalWeight` as you go; reject the result if its own
 * `share === 0` or association `state === 'disabled'` and recurse on the
 * remainder). Copied literally, not reinterpreted, even though it is not
 * the same shape as `StreamRotator`'s weighted pick.
 *
 * NOT ported (see docs/TRAFFIC_CORE_PLAN.md): entity binding / sticky
 * visitor selection (`EntityBindingService`, Redis) — part of the
 * "Визитор/уникальность" cluster.
 */
class LandingOfferRotator
{
    /**
     * @param list<array<string,mixed>> $associations Rows from
     *        `stream_landing_associations`/`stream_offer_associations`.
     * @return array<string,mixed>|null The resolved landing/offer row, or null.
     */
    public function getRandom(array $associations, string $entityTable, string $idColumn): ?array
    {
        if (empty($associations)) {
            return null;
        }

        $association = $this->rollDice($associations);
        if ($association === null) {
            return null;
        }

        $entity = $this->fetchEntity($entityTable, (int) $association[$idColumn]);

        if (!$this->isEntityOk($entity)) {
            $remaining = array_values(array_filter(
                $associations,
                static fn (array $a): bool => $a['id'] !== $association['id']
            ));

            return $this->getRandom($remaining, $entityTable, $idColumn);
        }

        return $entity;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>|null
     */
    private function rollDice(array $items): ?array
    {
        if (empty($items)) {
            return null;
        }

        shuffle($items);

        $totalWeight = 0;
        $selected = 0;

        foreach ($items as $i => $item) {
            $weight = (int) $item['share'];
            $rand = mt_rand(0, $totalWeight + $weight);

            if ($totalWeight <= $rand) {
                $selected = $i;
            }

            $totalWeight += $weight;
        }

        $selectedItem = $items[$selected];

        if ((int) $selectedItem['share'] !== 0 && $selectedItem['state'] !== 'disabled') {
            return $selectedItem;
        }

        unset($items[$selected]);

        return $this->rollDice(array_values($items));
    }

    private function isEntityOk(?array $entity): bool
    {
        return $entity !== null && ($entity['state'] ?? null) === 'active';
    }

    /**
     * @param "landings"|"offers" $table Always a literal from our own code.
     */
    private function fetchEntity(string $table, int $id): ?array
    {
        $pdo = Db::instance();
        $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
