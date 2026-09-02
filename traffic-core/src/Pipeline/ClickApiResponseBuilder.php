<?php

namespace TrafficCore\Pipeline;

/**
 * Port of legacy `Traffic\Dispatcher\ClickApiDispatcher::_forVersion2()`
 * (application/Traffic/Dispatcher/ClickApiDispatcher.php) — the ONE
 * response shape ported here (see this class's own docblock-equivalent
 * note in `docs/PORTING_LOG.md` Phase 17 for why v1/v3 are out of scope:
 * v1 is a strict subset of v2's info, no reason to maintain both; v3
 * needs the two-step JWT/cookie offer-redirect flow, which nothing in
 * traffic-core builds — every click here already behaves like legacy's
 * `force_redirect_offer=true`, which is v3's behavior ONLY when an
 * explicit override param is sent, otherwise v1/v2's).
 *
 * Legacy's v2 strips `Set-Cookie`/`Content-Type` from the header dump and
 * reports the body as an INT (its own byte length, not the actual
 * content — confirmed by reading `(int) $filteredResponse->getBody()`
 * literally) — this is a deliberate legacy choice (the API describes
 * what would happen, doesn't hand back full page bodies over JSON), kept
 * as-is here for parity. `uniqueness_cookie` mirrors legacy's cookie-jar
 * read but traffic-core has no `CookiesService`/uniqueness-cookie
 * concept (no cookie-based dedup exists in this project, see
 * `UniquenessService`'s own docblock — DB/Redis-driven instead) — always
 * null here, one documented field-level gap, not a missing feature this
 * project needs.
 */
class ClickApiResponseBuilder
{
    /** @return array<string,mixed> */
    public static function build(Payload $payload, bool $includeInfo): array
    {
        $headers = $payload->headers;
        $contentType = $headers['Content-Type'] ?? '';
        unset($headers['Content-Type'], $headers['Set-Cookie']);

        $json = [
            'body' => strlen($payload->body),
            'headers' => self::headersToList($headers),
            'status' => $payload->statusCode,
            'contentType' => $contentType,
            'uniqueness_cookie' => null,
        ];

        if ($includeInfo) {
            $json['info'] = [
                'type' => $payload->actionType,
                'url' => $payload->actionPayload,
                'campaign_id' => $payload->campaign['id'] ?? null,
                'stream_id' => $payload->stream['id'] ?? null,
                'landing_id' => $payload->landingId,
                'sub_id' => $payload->rawClick['sub_id'] ?? null,
                'is_bot' => (bool) ($payload->rawClick['is_bot'] ?? false),
                'offer_id' => $payload->offerId,
                'token' => $payload->lookupToken,
                'uniqueness' => [
                    'campaign' => (bool) ($payload->rawClick['is_unique_campaign'] ?? false),
                    'stream' => (bool) ($payload->rawClick['is_unique_stream'] ?? false),
                    'global' => (bool) ($payload->rawClick['is_unique_global'] ?? false),
                ],
            ];
        }

        return $json;
    }

    /** @return list<array{0:string,1:string}> */
    private static function headersToList(array $headers): array
    {
        $list = [];
        foreach ($headers as $name => $value) {
            $list[] = [$name, (string) $value];
        }

        return $list;
    }
}
