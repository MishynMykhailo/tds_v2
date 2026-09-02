<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Uniqueness\EntityBindingService;

/**
 * Port of legacy `Traffic\Actions\StreamRotator`
 * (application/Traffic/Pipeline/Rotator/StreamRotator.php).
 *
 * Ported: `chooseByPosition()` (ordered scan, first stream passing
 * `CheckFilters` wins) and `chooseByWeight()` (legacy `_rollDice()`:
 * shuffle candidates, weighted `mt_rand` pick, if the pick fails the
 * filter check drop it and recurse on the remainder).
 *
 * Sticky-stream binding (Phase 13): `chooseByWeight()` now ports
 * `_findBoundStream()` — if the campaign has `bind_visitors` enabled
 * (`type='weight'` AND a non-empty `bind_visitors` value — see
 * `Campaign::isBindVisitorsEnabled()`), check `EntityBindingService`
 * for a previously-bound stream id BEFORE rolling. **Literal legacy
 * quirk, ported as-is**: a bound stream match is returned WITHOUT
 * re-running `CheckFilters` on it (`_findBoundStream()` only checks
 * `$stream->getId() == $streamId` against the candidate list, no filter
 * call) — so a visitor's binding survives even if the stream's filters
 * would now reject them. After a fresh roll (no binding found, or
 * binding's stream missing from the current candidate list), the result
 * is bound for next time.
 */
class StreamRotator
{
    /**
     * @param array<string,mixed> $signal see Signal::fromRequest()
     * @param array<string,mixed>|null $campaign Full campaign row — pass
     *        null to disable sticky-stream binding entirely (e.g. from a
     *        call site that doesn't have visitor identity available).
     */
    public function __construct(
        private readonly array $signal,
        private readonly ?array $campaign = null,
    ) {
    }

    private function bindingEnabled(): bool
    {
        return $this->campaign !== null
            && ($this->campaign['type'] ?? null) === 'weight'
            && !empty($this->campaign['bind_visitors']);
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
        if (!$this->bindingEnabled()) {
            return $this->rollDice($streams);
        }

        $ip = (string) ($this->signal['ip'] ?? '');
        $userAgent = (string) ($this->signal['userAgent'] ?? '');
        $uniqueByIpUa = ($this->campaign['uniqueness_method'] ?? 'ip_ua') !== 'ip';
        $campaignId = (int) $this->campaign['id'];

        $binding = new EntityBindingService();
        $boundId = $binding->find(EntityBindingService::TYPE_STREAM, $campaignId, $ip, $userAgent, $uniqueByIpUa);

        if ($boundId !== null) {
            foreach ($streams as $stream) {
                if ((int) $stream['id'] === $boundId) {
                    return $stream;
                }
            }
        }

        $stream = $this->rollDice($streams);

        if ($stream !== null) {
            $ttlSeconds = (int) ($this->campaign['cookies_ttl'] ?? 0) * 3600;
            $binding->bind(EntityBindingService::TYPE_STREAM, $campaignId, $ip, $userAgent, $uniqueByIpUa, (int) $stream['id'], $ttlSeconds);
        }

        return $stream;
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
