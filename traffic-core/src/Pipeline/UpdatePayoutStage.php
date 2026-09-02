<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Pipeline\Stage\UpdatePayoutStage`
 * (application/Traffic/Pipeline/Stage/UpdatePayoutStage.php).
 *
 * Ported condition, read directly from `application/Traffic/Model/
 * Offer.php`: `isCPC()` is `payout_type == "CPC"` (`PAYOUT_TYPE_CPC`,
 * line ~19); `isPayoutAuto()` is the raw `payout_auto` column (line
 * ~57). When the chosen offer `isCPC() && !isPayoutAuto()`, mark the
 * click `is_sale = 1` and set `sale_revenue` from the offer's
 * `payout_value`.
 *
 * NOT ported: legacy's currency exchange
 * (`CurrencyService::instance()->exchange($offer->getPayoutValue(),
 * $offer->getPayoutCurrency(), ...settings 'currency')`) — traffic-core
 * has no currency-conversion infrastructure anywhere yet (same
 * documented gap as `BuildRawClickStage`/`UpdateCostsStage`), so the raw
 * `payout_value` is stored as-is, un-exchanged.
 *
 * `$payload` has no cached full offer row (`ChooseOfferStage` only sets
 * `offerId`), so this stage re-queries `offers` by id — same pattern as
 * `ChooseOfferStage` re-querying `stream_offer_associations`.
 */
class UpdatePayoutStage
{
    private const PAYOUT_TYPE_CPC = 'CPC';

    public function process(Payload $payload): Payload
    {
        if (empty($payload->rawClick) || $payload->offerId === null) {
            return $payload;
        }

        $offer = $this->findOffer($payload->offerId);

        if ($offer === null) {
            return $payload;
        }

        $isCPC = ($offer['payout_type'] ?? null) === self::PAYOUT_TYPE_CPC;
        $isPayoutAuto = (bool) $offer['payout_auto'];

        if ($isCPC && !$isPayoutAuto) {
            $payload->rawClick['is_sale'] = 1;
            $payload->rawClick['sale_revenue'] = $offer['payout_value'];
        }

        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function findOffer(int $offerId): ?array
    {
        $stmt = Db::instance()->prepare(
            'SELECT payout_type, payout_auto, payout_value, payout_currency FROM offers WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$offerId]);

        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
