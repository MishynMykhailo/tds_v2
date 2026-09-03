<?php

namespace TrafficCore\Pipeline;

use TrafficCore\ConversionCapacity\ConversionCapacityService;
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
 * NOT ported: the `IGNORE_OFFER_PARAM="exit"` param-skip, `needToken`
 * itself (no token flow to need one for).
 *
 * Phase 18 (2026-09-03, "добей хвосты" round): `ConversionCapacityService::
 * findAvailableOffer()` (daily-cap alternate-offer chain) IS now ported —
 * `resolveWithinCap()` below, applied to whichever offer either branch
 * resolves, exactly where legacy applies it (unconditionally, AFTER offer
 * resolution, before setting the action fields — see the real
 * `Traffic\Pipeline\Stage\ChooseOfferStage::process()`, read directly, not
 * assumed). `ConversionCapacityService` itself (`store()`/
 * `currentValueForOffer()`) lives in `src/ConversionCapacity/` — see its
 * own docblock for the Redis mechanism and why legacy's `FileStorage`
 * fallback isn't ported.
 *
 * REAL LEGACY BUG, found reading the source directly, NOT reproduced:
 * `ChooseOfferStage::process()` does
 * `if ($newOffer->getId() != $offer->getId())` right after calling
 * `findAvailableOffer($offer)` — but that method has no final `return`
 * statement on its "cap reached, no alternative_offer_id set" branch, so
 * it implicitly returns PHP `null`. Calling `->getId()` on that null
 * would be an uncaught fatal ("Call to a member function getId() on
 * null") in real legacy whenever a capped offer has no alternative
 * chain - same decompilation-bug shape as several other findings this
 * project has already catalogued (docs/PORTING_LOG.md). Ported
 * defensively instead: a null result here just means "no offer
 * available", same as the existing `if (empty($offer))` skip case a few
 * lines below already handles for the no-offer-at-all path.
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

            return $this->applyOffer($payload, $offer);
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

        return $this->applyOffer($payload, $offer);
    }

    /** @param array<string,mixed>|null $offer */
    private function applyOffer(Payload $payload, ?array $offer): Payload
    {
        if ($offer === null) {
            return $payload;
        }

        $timestamp = $payload->signal['datetime']?->getTimestamp() ?? time();
        $offer = $this->resolveWithinCap($offer, $timestamp);

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

    /**
     * Port of `ConversionCapacityService::findAvailableOffer()`. Follows
     * `alternative_offer_id` while the current offer's daily cap is
     * reached, same recursion-guard as legacy's `RecursionError` (a
     * chain that revisits an offer id just stops here instead of
     * throwing — see class docblock on why a hard error isn't
     * reproduced).
     *
     * @param  array<string,mixed>  $offer
     * @param  array<int,int>  $previousChecks
     * @return array<string,mixed>|null
     */
    private function resolveWithinCap(array $offer, int $timestamp, array $previousChecks = []): ?array
    {
        if (empty($offer['conversion_cap_enabled'])) {
            return $offer;
        }

        if (in_array((int) $offer['id'], $previousChecks, true)) {
            return null;
        }

        $service = new ConversionCapacityService();
        $currentValue = $service->currentValueForOffer(
            (int) $offer['id'],
            (string) ($offer['conversion_timezone'] ?? 'UTC'),
            $timestamp,
        );

        if ((int) $offer['daily_cap'] > $currentValue) {
            return $offer;
        }

        if (empty($offer['alternative_offer_id'])) {
            return null;
        }

        $stmt = Db::instance()->prepare("SELECT * FROM offers WHERE id = ? AND state = 'active' LIMIT 1");
        $stmt->execute([$offer['alternative_offer_id']]);
        $alternative = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        if ($alternative === null) {
            return null;
        }

        $previousChecks[] = (int) $offer['id'];

        return $this->resolveWithinCap($alternative, $timestamp, $previousChecks);
    }
}
