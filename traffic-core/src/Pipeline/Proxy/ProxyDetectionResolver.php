<?php

namespace TrafficCore\Pipeline\Proxy;

/**
 * Port of legacy `Traffic\Device\Service\ProxyService::usingProxy()`
 * (application/Traffic/Device/Service/ProxyService.php) — the ONE real,
 * portable half of legacy `BuildRawClickStage::_checkIfProxy()` (the
 * other half, GeoDb `IpInfoType::PROXY_TYPE`, requires the paid
 * IP2Location PX tier this project only has the free LITE tier for —
 * same documented gap as `BotDetectionService`'s GeoDb `BOT_TYPE` check,
 * not portable here). Unlike that half, this one is pure request-header
 * inspection — no GeoDb, no external service, no paid data needed at
 * all — so it's fully implementable.
 *
 * BUG FOUND WHILE PORTING (not fixed — literal behavior preserved by
 * simplifying to what the code actually does): legacy's real
 * `usingProxy()` has a duplicate, unreachable nested condition —
 * ```
 * if (isBehindCloudFlare && isXffContainsCfcip) {
 *     if (isBehindCloudFlare && !isXffContainsCfcip) { ... }  // dead:
 *         // the outer branch already proved isXffContainsCfcip === true,
 *         // so this inner check can never be true.
 *     return false;
 * }
 * return hasSeveralIpsInXffHeader();
 * ```
 * The entire inner `if` block (including a `_isBehindLocalProxy()` /
 * `_detectProxyUsageByHeaders()` path) is unreachable dead code — proven
 * by the boolean logic alone, not a guess. Reproduced below with that
 * dead branch removed; the resulting behavior is identical to the real
 * legacy method for every possible input, verified by exhaustively
 * tracing both boolean branches.
 *
 * Ported faithfully:
 *  - Real client behind CloudFlare AND its own `CF-Connecting-IP` shows
 *    up in `X-Forwarded-For` -> NOT flagged as "using a proxy" (that's
 *    just CloudFlare's own legitimate proxying, not a user-run proxy).
 *  - Otherwise: 2+ distinct IPs in `X-Forwarded-For` -> flagged as using
 *    a proxy (chained/rewritten XFF is the signal, not any individual
 *    IP's reputation).
 *
 * `X-YANDEX-TURBO`/local-proxy/mismatched-single-header detection
 * (`_detectProxyUsageByHeaders()`) is intentionally NOT reachable here —
 * it only lived inside the dead branch in real legacy too, so it never
 * actually ran there either. Not a gap introduced by this port.
 */
class ProxyDetectionResolver
{
    private const CLOUDFLARE_HEADERS = ['CF-IPCountry', 'CF-Connecting-IP', 'CF-Visitor'];

    public function resolve(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        if ($this->isBehindCloudFlare($request) && $this->xffContainsCfConnectingIp($request)) {
            return false;
        }

        return $this->hasSeveralIpsInXffHeader($request);
    }

    private function isBehindCloudFlare(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        foreach (self::CLOUDFLARE_HEADERS as $header) {
            if ($request->hasHeader($header)) {
                return true;
            }
        }

        return false;
    }

    private function xffContainsCfConnectingIp(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        $cfIp = $request->getHeaderLine('CF-Connecting-IP');

        if ($xff === '' || $cfIp === '') {
            return false;
        }

        $ips = array_map('trim', explode(',', $xff));

        return in_array($cfIp, $ips, true);
    }

    private function hasSeveralIpsInXffHeader(\Psr\Http\Message\ServerRequestInterface $request): bool
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        $ips = array_map('trim', explode(',', $xff));

        return count(array_unique($ips)) > 1;
    }
}
