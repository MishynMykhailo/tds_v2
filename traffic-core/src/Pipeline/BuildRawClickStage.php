<?php

namespace TrafficCore\Pipeline;

use TrafficCore\Pipeline\Visitor\DictionaryRepository;

/**
 * Port of legacy `Traffic\Pipeline\Stage\BuildRawClickStage`
 * (application/Traffic/Pipeline/Stage/BuildRawClickStage.php — 15
 * sub-steps: `_prepare`/`_findLanguage`/`_findOtherParams`/
 * `_findSeReferrer`/`_findReferrer`/`_findSource`/`_findXRequestedWith`/
 * `_findSearchEngine`/`_findKeyword`/`_findDefaultKeyword`/`_findCosts`/
 * `_findSubIds`/`_findExtraParams`/`_findIpInfo`/`_findDeviceInfo`, plus
 * `_checkIfBot`/`_checkIfProxy`).
 *
 * `_findIpInfo`/`_findDeviceInfo` are ported as `ResolveVisitorStage`
 * (runs earlier, real GeoDb + device data land on `visitors`, not
 * `clicks` — see that stage's docblock, matches legacy's own schema).
 * `_prepare`'s IP/UA capture is `CaptureSignalStage`/`Signal`, also
 * earlier in the pipeline. This class ports the rest: referrer/source/
 * se_referrer/search_engine/x_requested_with/keyword/cost/sub_ids/
 * extra_params/landing_id-via-lp_id/creative_id/ad_campaign_id/
 * external_id — every field that has a real destination column/`ref_*`
 * dictionary in this project's `clicks` schema (confirmed via live
 * `DESCRIBE`).
 *
 * Aliasable params (see `CheckParamAliasesStage`, runs earlier) are
 * consulted first via `payload->resolvedParams`, falling back to the
 * request's own params — same pattern for every field below.
 *
 * NOT ported: `language` — `clicks` has no `language` column at all in
 * this project's schema (confirmed via `DESCRIBE`), so there is nowhere
 * to put it; `currency` — same, `clicks.cost` exists but there's no
 * `currency` column. `_findKeyword()`'s referrer-based extraction
 * (`ReferrerParserService::parse()` — matches a URL against a database
 * of known search-engine URL patterns to pull out their query param) is
 * NOT ported — needs that whole search-engine-pattern dataset, out of
 * scope here; only the direct `?keyword=` param is honored. Bot
 * detection (`_checkIfBot`) and proxy detection (`_checkIfProxy`) are
 * NOT ported — separate, already-deferred clusters (`BotDetection`
 * runtime check, `ProxyService`) — `clicks.is_bot`/`is_using_proxy` stay
 * at their column defaults (`0`).
 */
class BuildRawClickStage
{
    public function process(Payload $payload): Payload
    {
        $dict = new DictionaryRepository();
        $params = $payload->signal['params'] ?? [];
        $resolved = $payload->resolvedParams;

        $get = static fn (string $name): ?string => isset($resolved[$name])
            ? $resolved[$name]
            : (isset($params[$name]) && $params[$name] !== '' ? (string) $params[$name] : null);

        $referrer = $this->findReferrer($get, $payload);
        $seReferrer = $get('se_referrer');
        $source = $this->findSource($get, $referrer);
        $searchEngine = $this->findSearchEngine($get, $seReferrer);
        $keyword = $get('keyword') ?? $get('default_keyword');
        $xRequestedWith = $payload->request->getHeaderLine('X-Requested-With') ?: null;

        $lpId = $get('lp_id');
        $landingId = $payload->landingId ?? ($lpId !== null ? (int) $lpId : null);

        $payload->rawClick = [
            'visitor_id' => $payload->visitorId,
            'sub_id' => bin2hex(random_bytes(16)),
            'datetime' => gmdate('Y-m-d H:i:s'),
            'campaign_id' => $payload->campaign['id'],
            'parent_campaign_id' => $payload->parentCampaignId,
            'stream_id' => $payload->stream['id'] ?? null,
            'landing_id' => $landingId,
            'offer_id' => $payload->offerId,
            // source_id/referrer_id are NOT NULL on `clicks` (unlike every
            // other dictionary FK added here) — 0 is the "unresolved"
            // sentinel, matching this file's original Phase-1 placeholder
            // values; dictionary ids auto-increment from 1, so 0 never
            // collides with a real row. Live-verified: a click with no
            // referrer at all threw a NOT NULL constraint violation before
            // this fallback was added.
            'source_id' => $dict->findOrCreateByValue('ref_sources', $source) ?? 0,
            'referrer_id' => $dict->findOrCreateByValue('ref_referrers', $this->truncateReferrer($referrer)) ?? 0,
            'search_engine_id' => $dict->findOrCreateByValue('ref_search_engines', $searchEngine),
            'keyword_id' => $dict->findOrCreateByValue('ref_keywords', $keyword),
            'ad_campaign_id_id' => $dict->findOrCreateByValue('ref_ad_campaign_ids', $get('ad_campaign_id')),
            'x_requested_with_id' => $dict->findOrCreateByValue('ref_x_requested_with', $xRequestedWith),
            'creative_id_id' => $dict->findOrCreateByValue('ref_creative_ids', $get('creative_id')),
            'external_id_id' => $dict->findOrCreateByValue('ref_external_ids', $get('external_id')),
            'cost' => $get('cost') ?? 0,
        ];

        for ($i = 1; $i <= 15; $i++) {
            $value = $get('sub_id_' . $i) ?? $get('subid' . $i);
            $payload->rawClick['sub_id_' . $i . '_id'] = $dict->findOrCreateByValue('ref_sub_ids', $value);
        }

        for ($i = 1; $i <= 10; $i++) {
            $payload->rawClick['extra_param_' . $i] = $get('extra_param_' . $i);
        }

        return $payload;
    }

    /** @param callable(string):?string $get */
    private function findReferrer(callable $get, Payload $payload): ?string
    {
        $referrer = $get('referrer');
        if ($referrer !== null) {
            return urldecode($referrer);
        }

        $header = $payload->signal['referer'] ?? '';

        return $header !== '' ? $header : null;
    }

    /** @param callable(string):?string $get */
    private function findSource(callable $get, ?string $referrer): ?string
    {
        $source = $get('source');
        if ($source !== null) {
            return $source;
        }

        if ($referrer && preg_match('#https?://(.*?)/#si', $referrer, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /** @param callable(string):?string $get */
    private function findSearchEngine(callable $get, ?string $seReferrer): ?string
    {
        $se = $get('se');
        if ($se !== null) {
            return $se;
        }

        if ($seReferrer) {
            $host = parse_url($seReferrer, PHP_URL_HOST);
            if ($host) {
                return $host;
            }
        }

        return null;
    }

    private function truncateReferrer(?string $referrer): ?string
    {
        return $referrer !== null ? mb_substr($referrer, 0, 250) : null;
    }
}
