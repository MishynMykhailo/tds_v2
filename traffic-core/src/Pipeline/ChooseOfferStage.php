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
 * **CORRECTED (2026-08-29, see docs/PORTING_LOG.md — this was originally
 * mis-documented as a deliberate deviation, it is not one):** setting
 * `actionType`/`actionPayload` from the offer directly, unconditionally,
 * IS the faithful 1:1 port for this flow. `isForceRedirectOffer` is not
 * a global default — it's a per-entry-point flag each dispatcher sets
 * when constructing its `Payload`. `Traffic\Dispatcher\ClickDispatcher`
 * (the plain tracking-link click — exactly what this pipeline models)
 * constructs its `Payload` with `force_redirect_offer => true`
 * UNCONDITIONALLY (confirmed by reading `ClickDispatcher.php` directly),
 * so legacy's real behavior in THIS flow also sets the action from the
 * offer directly — no token/JWT step. The `needToken`/
 * `isForceRedirectOffer=false` two-step flow is real, but it belongs to
 * OTHER entry points: `ClickApiContext`/`KtrkContext` (ported Phase 17,
 * still without the JWT/cookie redirect step — see `ClickApiSignalStage`/
 * `docs/PORTING_LOG.md` Phase 17 for that documented, separate gap) and
 * `LandingOfferContext` (also Phase 17 — reads the offer chosen by THIS
 * class via `payload->forcedOfferId`, not via a token-redirect hop).
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

        // See class docblock: this IS legacy's real behavior for the plain
        // click flow (ClickDispatcher forces force_redirect_offer=true
        // unconditionally), not a deviation from it.
        $payload->actionType = $offer['action_type'];
        $payload->actionPayload = $offer['action_payload'];
        $payload->actionOptions = $offer['action_options'] ?? null;

        return $payload;
    }
}
