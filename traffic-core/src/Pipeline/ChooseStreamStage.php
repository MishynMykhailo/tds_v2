<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\ChooseStreamStage`
 * (application/Traffic/Pipeline/Stage/ChooseStreamStage.php) — Phase 2.
 *
 * Ported for real: the three stream-type tiers exactly as legacy resolves
 * them:
 *   1. `type='forced'` — chooseByPosition() (position ASC, first to pass
 *      CheckFilters wins).
 *   2. `type='regular'` — chooseByPosition() if `campaigns.type ===
 *      'position'`, else chooseByWeight() (legacy default, weighted
 *      random via StreamRotator::_rollDice()).
 *   3. `type='default'` — first stream of this type, **no CheckFilters
 *      call at all**. This mirrors legacy exactly: `$streams =
 *      $groupedStreams->byType(TYPE_DEFAULT); $stream = empty($streams) ?
 *      NULL : $streams[0];` — filters are never consulted for the
 *      default/fallback stream, this is not a simplification made here.
 *
 * Phase 13: sticky-stream binding is now real — see `StreamRotator`'s
 * own docblock, ported inside `chooseByWeight()` rather than here.
 *
 * Phase 17: `payload->forcedStreamId` (set by `public/landing-offer.php`
 * when re-resolving a click whose stream was already decided on the
 * FIRST pass) short-circuits all three tiers — mirrors `FindCampaignStage`'s
 * `forcedCampaignId` handling exactly (resolve by id directly, consume
 * and null the field, skip rotation entirely).
 *
 * `payload->isBot`/`payload->isUsingProxy` (both resolved by
 * `ResolveVisitorStage`, which runs before this stage) are passed into
 * `StreamRotator` so `CheckFilters` can evaluate `bot`/`proxy`-type
 * `StreamFilter`s — the real, current legacy mechanism for routing
 * bot/proxy traffic to a dedicated `type='forced'` stream (see
 * `Filters\FilterEngine::bot()`/`proxy()`'s docblocks).
 *
 * NOT ported (see docs/TRAFFIC_CORE_PLAN.md): the `landings`/`offers`
 * schema branch (handled by separate `ChooseLandingStage`/
 * `ChooseOfferStage` stages since Phase 3, unrelated to this class).
 * Phase 4: `CheckFilters`
 * now runs a real per-filter engine (`Filters\FilterEngine`) using
 * `$payload->signal` (`CaptureSignalStage`, runs before this stage) —
 * see CheckFilters' own docblock for the implemented/deferred filter list.
 */
class ChooseStreamStage
{
    public function process(Payload $payload): Payload
    {
        $pdo = Db::instance();

        if ($payload->forcedStreamId !== null) {
            $stmt = $pdo->prepare("SELECT * FROM streams WHERE id = ? AND state = 'active' LIMIT 1");
            $stmt->execute([$payload->forcedStreamId]);
            $stream = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            $payload->forcedStreamId = null;

            if ($stream === null) {
                return $payload;
            }

            $payload->stream = $stream;
            if (!in_array($stream['schema'], ['landings', 'offers'], true)) {
                $payload->actionType = $stream['action_type'];
                $payload->actionPayload = $stream['action_payload'];
            }

            return $payload;
        }

        $campaignId = $payload->campaign['id'];
        $campaignType = $payload->campaign['type'] ?? 'weight';

        $rotator = new StreamRotator($payload->signal, $payload->campaign, $payload->isBot, $payload->isUsingProxy);
        $stream = null;

        // Tier 1: forced.
        $stream = $rotator->chooseByPosition($this->streamsByType($pdo, $campaignId, 'forced'));

        // Tier 2: regular.
        if ($stream === null) {
            $regular = $this->streamsByType($pdo, $campaignId, 'regular');
            $stream = $campaignType === 'position'
                ? $rotator->chooseByPosition($regular)
                : $rotator->chooseByWeight($regular);
        }

        // Tier 3: default — no filter check, first row wins.
        if ($stream === null) {
            $default = $this->streamsByType($pdo, $campaignId, 'default');
            $stream = $default[0] ?? null;
        }

        if ($stream === null) {
            // Mirrors legacy _setNoDirection(): no actionType set, pipeline
            // falls through to an empty/default response.
            return $payload;
        }

        $payload->stream = $stream;

        if (!in_array($stream['schema'], ['landings', 'offers'], true)) {
            $payload->actionType = $stream['action_type'];
            $payload->actionPayload = $stream['action_payload'];
        }

        return $payload;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function streamsByType(\PDO $pdo, int|string $campaignId, string $type): array
    {
        $stmt = $pdo->prepare(
            "SELECT * FROM streams
             WHERE campaign_id = ? AND state = 'active' AND type = ?
             ORDER BY position ASC"
        );
        $stmt->execute([$campaignId, $type]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }
}
