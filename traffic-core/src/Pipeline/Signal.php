<?php

namespace TrafficCore\Pipeline;

/**
 * Real per-request signal extraction from the PSR-7 request — did not
 * exist before Phase 4 (BuildRawClickStage's docblock listed "IP/UA
 * capture" as NOT ported). Needed for the `StreamFilters` engine
 * (`Filters\FilterEngine`) to have anything real to check against.
 *
 * Deliberately NOT done here: X-Forwarded-For / proxy-chain trust
 * resolution — `REMOTE_ADDR` only. Trusting a client-supplied XFF header
 * without a configured trusted-proxy list is its own unresolved question
 * in legacy too (`Proxy` filter, GeoDb IP resolution — separate deferred
 * clusters), not decided here.
 */
class Signal
{
    /**
     * @return array{ip:string,referer:string,userAgent:string,language:string,params:array<string,mixed>,datetime:\DateTimeImmutable}
     */
    public static function fromRequest(\Psr\Http\Message\ServerRequestInterface $request): array
    {
        $server = $request->getServerParams();
        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        return [
            'ip' => (string) ($server['REMOTE_ADDR'] ?? ''),
            'referer' => $request->getHeaderLine('Referer'),
            'userAgent' => $request->getHeaderLine('User-Agent'),
            'language' => self::primaryLanguage($request->getHeaderLine('Accept-Language')),
            // GET takes priority over POST on key collision — mirrors legacy getParam().
            'params' => array_merge($body, $query),
            'datetime' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }

    private static function primaryLanguage(string $acceptLanguage): string
    {
        if ($acceptLanguage === '') {
            return '';
        }

        $first = trim(explode(',', $acceptLanguage)[0]);
        $first = explode(';', $first)[0];

        return trim(explode('-', $first)[0]);
    }
}
