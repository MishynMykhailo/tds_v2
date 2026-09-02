<?php

namespace TrafficCore\Pipeline;

/**
 * Signal capture for the AJAX Click API entry point (`public/click-api.php`,
 * port of legacy `Traffic\Context\ClickApiContext::modifyRequest()`/
 * `_replaceRawClickParams()` — application/Traffic/Context/
 * ClickApiContext.php). Replaces `CaptureSignalStage` for this one entry
 * point rather than extending it: legacy's real mechanism rewrites the
 * PSR-7 request itself (`withHeaders()`/`withServerParams()`) before the
 * pipeline runs, so `REMOTE_ADDR`/`User-Agent`/`Referer` land on the
 * request the SAME way a normal click's would. traffic-core's `Signal`
 * (unlike legacy's `RawClick`/`ServerRequest` pair) is the single thing
 * every downstream stage reads (confirmed by grepping `->signal[` across
 * `traffic-core/src` — GeoDb/device resolution, filters, uniqueness,
 * macros, all of it) and PSR-7's `ServerRequestInterface` has no
 * `withServerParams()` to fake `REMOTE_ADDR` through anyway — so this
 * builds the `Signal` array directly from explicit params where given,
 * falling back to the real request exactly like `Signal::fromRequest()`,
 * which achieves the identical end state with less indirection.
 *
 * Ported: `ip` (`?ip=`), `user_agent` (`?ua=`/`?user_agent=`), `referrer`
 * (`?referrer=`/`?referer=`) — legacy's `_apiParams` aliases for these
 * three, confirmed by reading `ClickApiContext::$_apiParams`.
 *
 * NOT ported (documented, not silently dropped — see PORTING_LOG.md
 * Phase 17): `language`/`search_engine` overrides (legacy sets these on
 * `RawClick` directly, bypassing normal resolution — traffic-core's
 * `BuildRawClickStage` always resolves `search_engine` from the referrer
 * itself, and `language` isn't read by anything downstream besides the
 * `lang` filter, low value to special-case here), `landing_id` override
 * (would need `ChooseLandingStage` to gain a "pre-selected landing" short
 * circuit — legacy's own docblock-adjacent code marks this an edge case
 * even there), `datetime` override (historical/backfill click reporting,
 * a bulk-import concern, not this entry point's main use case),
 * `always_empty_cookies` (forces sticky stream/landing/offer binding to
 * be ignored for this one request — `EntityBindingService`'s binding
 * lookup has no such bypass flag yet).
 */
class ClickApiSignalStage
{
    public function process(Payload $payload): Payload
    {
        $signal = Signal::fromRequest($payload->request);
        $params = $signal['params'];

        $ip = $params['ip'] ?? null;
        if ($ip !== null && $ip !== '') {
            $signal['ip'] = (string) $ip;
        }

        $userAgent = $params['ua'] ?? $params['user_agent'] ?? null;
        if ($userAgent !== null && $userAgent !== '') {
            $signal['userAgent'] = (string) $userAgent;
        }

        $referrer = $params['referrer'] ?? $params['referer'] ?? null;
        if ($referrer !== null && $referrer !== '') {
            $signal['referer'] = (string) $referrer;
        }

        $payload->signal = $signal;

        return $payload;
    }
}
