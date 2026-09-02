<?php

namespace TrafficCore\Pipeline;

use TrafficCore\LpToken\LpTokenService;

/**
 * Port of legacy `Traffic\Pipeline\Stage\GenerateTokenStage`
 * (application/Traffic/Pipeline/Stage/GenerateTokenStage.php) — the
 * server-side lookup-token flow: when a stream picks an offer, generate a
 * signed token and store the click's raw data in Redis under it with a
 * TTL, so a later (not-yet-built) postback/conversion callback can look
 * up which click it belongs to. Unrelated to `double_meta`'s JWT — see
 * `TrafficCore\LpToken\LpTokenKey`'s docblock for that mechanism.
 *
 * **Condition ported**: legacy gates on `Payload::isTokenNeeded()`, a
 * flag set unconditionally by `ChooseOfferStage` the instant any offer is
 * chosen (`$payload->setNeedToken(true);` right after `$payload->
 * setOffer($offer);` — application/Traffic/Pipeline/Stage/
 * ChooseOfferStage.php) — plus a stream-has-offers re-check inside
 * `GenerateTokenStage` itself that is always true by construction once
 * `setNeedToken(true)` has already run down that same code path. This
 * traffic-core port has no `needToken` flag (not added — `ChooseOfferStage`/
 * `ChooseLandingStage` are off-limits for this change) and conditions
 * directly on `$payload->offerId !== null` instead, which is set by
 * traffic-core's own `ChooseOfferStage::process()` exactly when — and
 * only when — an offer was actually chosen (see that class: `$payload->
 * offerId = (int) $offer['id'];` right before it sets the action from the
 * offer). Functionally identical to legacy's gate for this flow.
 *
 * **`shouldAddTokenToURL()` investigated, NOT ported — verified false in
 * the modeled flow, not guessed.** Legacy's `Payload::$_addTokenToUrl`
 * defaults to `NULL` (application/Traffic/Pipeline/Payload.php:26,
 * `private $_addTokenToUrl = NULL;`) and `Traffic\Dispatcher\
 * ClickDispatcher::dispatch()` — the flow this project models — never
 * calls `setAddTokenToUrl()` on the `Payload` it constructs (it only sets
 * `force_redirect_offer => true`; confirmed by reading the full
 * constructor call in application/Traffic/Dispatcher/ClickDispatcher.php).
 * The ONLY call site of `setAddTokenToUrl()` anywhere in legacy
 * (`grep -rn "setAddTokenToUrl" application/` — one hit outside
 * `Payload.php` itself) is `ChooseLandingStage::_updatePayload()`:
 *   ```
 *   if ($payload->getStream() && ...hasCachedOffers($payload->getStream())) {
 *       $payload->setNeedToken(true);
 *       $payload->setAddTokenToUrl(true);
 *   }
 *   ```
 * (application/Traffic/Pipeline/Stage/ChooseLandingStage.php, inside
 * `_updatePayload()`, only reached when a LANDING was chosen) — i.e. only
 * when the stream serves a landing page that itself has offers behind
 * it, so the landing page can pick up `_subid`/`_token` from its own URL
 * to attribute a later client-side redirect to the offer.
 * `ChooseOfferStage` — the direct offer-redirect path this task is
 * about — never calls `setAddTokenToUrl()` at all, so it stays at its
 * `NULL` (falsy) default there. Structurally the same holds here:
 * traffic-core's `ChooseLandingStage::process()` sets `landingId` (not
 * `offerId`) when a landing is chosen, and `ChooseOfferStage::process()`
 * returns early whenever `landingId !== null` — so this stage's own
 * `offerId !== null` gate can never be true on a request that went
 * through the landing-with-offers-behind-it branch. Conclusion: URL
 * mutation (`_subid=`/`_token=` query params on the redirect URL) is a
 * **documented no-op in every flow this stage actually runs in** — not
 * implemented. `Traffic\Service\UrlService::addParameterToUrl()`
 * (application/Traffic/Service/UrlService.php) was read for completeness
 * but has no equivalent here because nothing in this port calls it.
 *
 * Runs AFTER `BuildRawClickStage` (needs `$payload->rawClick` already
 * populated to store it) and BEFORE `ExecuteActionStage` (so a future
 * URL-mutating flow, if traffic-core's landing-with-offers-behind-it path
 * is ever ported, has the token available before the redirect fires) —
 * exact insertion point decided by the coordinator wiring this stage
 * into `public/index.php`'s pipeline array.
 */
class GenerateTokenStage
{
    public function process(Payload $payload): Payload
    {
        if ($payload->offerId === null) {
            return $payload;
        }

        if (empty($payload->rawClick)) {
            return $payload;
        }

        $service = new LpTokenService();
        $ttlSeconds = $service->getTtlSeconds();
        $token = $service->storeRawClick($payload->rawClick, $ttlSeconds);

        // Deliberately NOT written into $payload->rawClick: that same
        // array is later passed as-is to a PDO named-parameter INSERT in
        // `StoreRawClickStage` (`$stmt->execute($payload->rawClick)`)
        // against a prepared statement with exactly 10 placeholders and
        // no `token` column on `clicks` — verified live that PDO (both
        // sqlite and this project's actual MySQL driver) throws
        // "SQLSTATE[HY093]: Invalid parameter number" when the bound
        // array has any key beyond the statement's own placeholders, so
        // adding one here would break every click that reaches an offer.
        // Stored instead on `$payload->lookupToken` (new property, see
        // this stage's docblock / the porting report for the coordinator
        // to add to Payload) purely for any later stage/logging that
        // wants it — nothing in traffic-core reads it yet.
        $payload->lookupToken = $token;

        return $payload;
    }
}
