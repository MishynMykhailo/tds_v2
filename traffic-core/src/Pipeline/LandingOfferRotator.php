<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;
use TrafficCore\Uniqueness\EntityBindingService;

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
 * Sticky landing/offer binding (Phase 13): pass `$campaign`+`$signal`+
 * `$bindType` to enable — ports `LandingOfferRotator::_getBindEnabled()`
 * (`Campaign::isBindVisitorsLandingEnabled()`/`isBindVisitorsOfferEnabled()`
 * — cumulative `bind_visitors` string-length gate, 2+ chars for landing,
 * 3+ for offer, both also requiring `campaigns.type === 'weight'`).
 * Unlike `StreamRotator`'s literal-ported quirk, a bound entity here IS
 * re-validated via `isEntityOk()` (`state=active`) before being
 * returned, matching legacy: `_getEntityFromAssociation()`'s bound path
 * still resolves through the real repository lookup, not a raw id
 * match against the candidate list.
 */
class LandingOfferRotator
{
    /**
     * @param list<array<string,mixed>> $associations Rows from
     *        `stream_landing_associations`/`stream_offer_associations`.
     * @param array<string,mixed>|null $campaign Full campaign row — null
     *        disables sticky binding.
     * @param array<string,mixed>|null $signal see Signal::fromRequest()
     * @return array<string,mixed>|null The resolved landing/offer row, or null.
     */
    public function getRandom(
        array $associations,
        string $entityTable,
        string $idColumn,
        ?array $campaign = null,
        ?array $signal = null,
        ?string $bindType = null,
    ): ?array {
        if (empty($associations)) {
            return null;
        }

        $bindingEnabled = $campaign !== null && $signal !== null && $bindType !== null
            && $this->bindingEnabled($campaign, $bindType);

        if ($bindingEnabled) {
            $bound = $this->findBoundEntity($campaign, $signal, $bindType, $entityTable, $idColumn, $associations);
            if ($bound !== null) {
                return $bound;
            }
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

            return $this->getRandom($remaining, $entityTable, $idColumn, $campaign, $signal, $bindType);
        }

        if ($bindingEnabled) {
            $this->bind($campaign, $signal, $bindType, (int) $entity['id']);
        }

        return $entity;
    }

    private function bindingEnabled(array $campaign, string $bindType): bool
    {
        if (($campaign['type'] ?? null) !== 'weight') {
            return false;
        }

        $bindVisitors = (string) ($campaign['bind_visitors'] ?? '');
        $minLength = $bindType === EntityBindingService::TYPE_LANDING ? 2 : 3;

        return strlen($bindVisitors) >= $minLength;
    }

    /**
     * @param list<array<string,mixed>> $associations
     */
    private function findBoundEntity(array $campaign, array $signal, string $bindType, string $entityTable, string $idColumn, array $associations): ?array
    {
        [$ip, $userAgent, $uniqueByIpUa, $campaignId] = $this->identity($campaign, $signal);

        $boundId = (new EntityBindingService())->find($bindType, $campaignId, $ip, $userAgent, $uniqueByIpUa);
        if ($boundId === null) {
            return null;
        }

        foreach ($associations as $association) {
            if ((int) $association[$idColumn] === $boundId) {
                $entity = $this->fetchEntity($entityTable, $boundId);

                return $this->isEntityOk($entity) ? $entity : null;
            }
        }

        return null;
    }

    private function bind(array $campaign, array $signal, string $bindType, int $entityId): void
    {
        [$ip, $userAgent, $uniqueByIpUa, $campaignId] = $this->identity($campaign, $signal);
        $ttlSeconds = (int) ($campaign['cookies_ttl'] ?? 0) * 3600;

        (new EntityBindingService())->bind($bindType, $campaignId, $ip, $userAgent, $uniqueByIpUa, $entityId, $ttlSeconds);
    }

    /** @return array{0:string,1:string,2:bool,3:int} */
    private function identity(array $campaign, array $signal): array
    {
        $ip = (string) ($signal['ip'] ?? '');
        $userAgent = (string) ($signal['userAgent'] ?? '');
        $uniqueByIpUa = ($campaign['uniqueness_method'] ?? 'ip_ua') !== 'ip';
        $campaignId = (int) $campaign['id'];

        return [$ip, $userAgent, $uniqueByIpUa, $campaignId];
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
