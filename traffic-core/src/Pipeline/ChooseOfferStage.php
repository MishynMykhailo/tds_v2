<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Db;
use TrafficCore\Uniqueness\EntityBindingService;

/**
 * Port of legacy `Traffic\Pipeline\Stage\ChooseOfferStage`
 * (application/Traffic/Pipeline/Stage/ChooseOfferStage.php) — Phase 3.
 *
 * Ported: skip unless `stream.schema` is `landings`/`offers`; skip if a
 * landing was already chosen this request (`isForceChooseOffer` is never
 * true here — no query-param override implemented — so this reduces to
 * legacy's `if (!empty($landing) && !isForceChooseOffer) skip` exactly);
 * otherwise pick an offer via `LandingOfferRotator` over
 * `stream_offer_associations`, set `payload.offerId`.
 *
 * **Deliberate deviation from legacy** (documented in
 * docs/TRAFFIC_CORE_PLAN.md Phase 3 and docs/PORTING_LOG.md — not a bug):
 * real `ChooseOfferStage` does NOT set `actionType`/`actionPayload` from
 * the chosen offer directly. It sets `needToken=true`, and the actual
 * redirect happens later via a JWT-token two-step flow
 * (`GenerateTokenStage` + the `GatewayRedirectContext` second hop found
 * during Phase 1 — see docs/TRAFFIC_CORE_PLAN.md) gated behind
 * `isForceRedirectOffer` (default false, so legacy's default behavior
 * here is actually to NOT set an action from the offer at all). Since
 * that token flow is not implemented in traffic-core at all, replicating
 * the gate literally would mean a pure `schema=offers` stream (no
 * landings configured) picks an offer that never redirects anywhere —
 * useless for this proof of concept. So this port sets
 * `actionType`/`actionPayload`/`actionOptions` from the offer
 * unconditionally instead of gating on `isForceRedirectOffer`.
 *
 * Phase 13: sticky-offer binding is now real — see
 * `LandingOfferRotator`'s own docblock.
 *
 * Phase 17: `payload->forcedOfferId` (set by `public/landing-offer.php`,
 * legacy's `forcedOfferId` this class's own docblock previously listed as
 * NOT ported — now is, for that one caller) resolves the offer by id
 * directly and BYPASSES the `landingId !== null` skip check above it —
 * deliberately, since `LandingOfferDispatcher`'s whole premise is "a
 * landing was already shown, now report/confirm which offer" (`landingId`
 * being non-null is exactly the normal case there, not the exception).
 *
 * NOT ported: the `IGNORE_OFFER_PARAM="exit"` param-skip,
 * `ConversionCapacityService::findAvailableOffer()` (daily-cap
 * alternate-offer chain — `offers.conversion_cap_enabled`/`daily_cap`
 * columns exist in `backend/` but no runtime check reads them yet),
 * `needToken` itself (no token flow to need one for).
 */
class ChooseOfferStage
{
    public function process(Payload $payload): Payload
    {
        $stream = $payload->stream;

        if ($stream === null || !in_array($stream['schema'], ['landings', 'offers'], true)) {
            return $payload;
        }

        if ($payload->forcedOfferId !== null) {
            $forcedOfferId = $payload->forcedOfferId;
            $payload->forcedOfferId = null;

            $stmt = Db::instance()->prepare("SELECT * FROM offers WHERE id = ? AND state = 'active' LIMIT 1");
            $stmt->execute([$forcedOfferId]);
            $offer = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

            if ($offer === null) {
                return $payload;
            }

            $payload->offerId = (int) $offer['id'];
            $payload->actionType = $offer['action_type'];
            $payload->actionPayload = $offer['action_payload'];
            $payload->actionOptions = $offer['action_options'] ?? null;

            return $payload;
        }

        if ($payload->landingId !== null) {
            return $payload;
        }

        $pdo = Db::instance();
        $stmt = $pdo->prepare('SELECT * FROM stream_offer_associations WHERE stream_id = ?');
        $stmt->execute([$stream['id']]);
        $associations = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (empty($associations)) {
            return $payload;
        }

        $offer = (new LandingOfferRotator())->getRandom(
            $associations,
            'offers',
            'offer_id',
            $payload->campaign,
            $payload->signal,
            EntityBindingService::TYPE_OFFER,
        );

        if ($offer === null) {
            return $payload;
        }

        $payload->offerId = (int) $offer['id'];

        // See class docblock: deviation from legacy's needToken/
        // isForceRedirectOffer gate — token flow isn't implemented, so we
        // redirect directly from the offer's own action.
        $payload->actionType = $offer['action_type'];
        $payload->actionPayload = $offer['action_payload'];
        $payload->actionOptions = $offer['action_options'] ?? null;

        return $payload;
    }
}
