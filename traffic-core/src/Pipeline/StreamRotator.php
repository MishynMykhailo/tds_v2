<?php

namespace TrafficCore\Pipeline;

/**
 * Port of legacy `Traffic\Actions\StreamRotator`
 * (application/Traffic/Pipeline/Rotator/StreamRotator.php).
 *
 * Ported: `chooseByPosition()` (ordered scan, first stream passing
 * `CheckFilters` wins) and `chooseByWeight()` (legacy `_rollDice()`:
 * shuffle candidates, weighted `mt_rand` pick, if the pick fails the
 * filter check drop it and recurse on the remainder).
 *
 * NOT ported: visitor entity binding / sticky streams
 * (`EntityBindingService`, `Campaign::isBindVisitorsEnabled()`, Redis
 * bind-by-visitor lookup in `chooseByWeight()`'s `_findBoundStream()`) —
 * part of the separate "Визитор/уникальность" cluster already listed as
 * deferred in docs/TRAFFIC_CORE_PLAN.md. This class always goes straight
 * to the roll, never checks for a previously-bound stream.
 */
class StreamRotator
{
    /** @param array<string,mixed> $signal see Signal::fromRequest() */
    public function __construct(private readonly array $signal)
    {
    }

    /**
     * @param list<array<string,mixed>> $streams
     * @return array<string,mixed>|null
     */
    public function chooseByPosition(array $streams): ?array
    {
        foreach ($streams as $stream) {
            if (CheckFilters::isPass($stream, $this->signal)) {
                return $stream;
            }
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $streams
     * @return array<string,mixed>|null
     */
    public function chooseByWeight(array $streams): ?array
    {
        return $this->rollDice($streams);
    }

    /**
     * @param list<array<string,mixed>> $streams
     * @return array<string,mixed>|null
     */
    private function rollDice(array $streams): ?array
    {
        if (count($streams) === 0) {
            return null;
        }

        shuffle($streams);

        $totalWeight = 0;
        foreach ($streams as $stream) {
            $totalWeight += (int) $stream['weight'];
        }

        if ($totalWeight === 0) {
            return null;
        }

        $rand = mt_rand(0, $totalWeight - 1);
        $currentWeight = 0;

        foreach ($streams as $i => $stream) {
            $weight = (int) $stream['weight'];

            if ($currentWeight <= $rand && $rand < $currentWeight + $weight) {
                if (CheckFilters::isPass($stream, $this->signal)) {
                    return $stream;
                }

                $remaining = $streams;
                unset($remaining[$i]);

                return $this->rollDice(array_values($remaining));
            }

            $currentWeight += $weight;
        }

        return null;
    }
}
