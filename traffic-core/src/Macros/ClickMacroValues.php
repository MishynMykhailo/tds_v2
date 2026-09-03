<?php

namespace TrafficCore\Macros;

use TrafficCore\Db;
use TrafficCore\Pipeline\Payload;

/**
 * Builds the click-context macro name => value map `MacrosProcessor`
 * substitutes with — the traffic-core equivalent of legacy's
 * `MacroRepository::loadMacros()`'s ~30 registered click macros
 * (application/Traffic/Macros/MacroRepository.php), reduced to the
 * subset with real, non-fabricated data in this project.
 *
 * Ported (real data exists): `sub_id`/`subid` (alias), `sub_id_1..15`,
 * `extra_param_1..10`, `source`, `referrer`, `search_engine`, `keyword`,
 * `ad_campaign_id`, `creative_id`, `external_id`, `x_requested_with`,
 * `cost`, `revenue` (sum of `lead_revenue`/`sale_revenue`/
 * `rejected_revenue` on `rawClick` — matches legacy `RawClick::
 * getRevenue()`), `profit` (`revenue - cost`), `campaign_id`,
 * `campaign_name`, `stream_id`, `landing_id`, `offer_id`,
 * `parent_campaign_id`, `country`/`region`/`city` (from `ResolveVisitorStage`'s
 * `payload->geoDevice['geo']`), `device_type`/`device_model`/`browser`/
 * `browser_version`/`os`/`os_version` (from `payload->geoDevice['device']`),
 * `ip`, `user_agent`, `language`, `current_domain`, `date`, `random`,
 * `token` (the Redis lookup token, if one was generated), `debug`,
 * `is_bot`/`is_using_proxy` (real values since `BuildRawClickStage`'s
 * `BotDetectionService`/`ProxyDetectionResolver` wiring — see those
 * classes; both run before this one in the pipeline, so
 * `rawClick['is_bot']`/`rawClick['is_using_proxy']` are always already
 * resolved by the time a macro gets substituted).
 *
 * Explicitly NOT ported, with reasons (matches this project's existing
 * per-field gap notes elsewhere, not new decisions made here):
 *  - `country_code`/`country_name`/`region_name` language-translated
 *    variants (`{country:ru}` etc.) — legacy's translation dictionaries
 *    (`CountriesRepository::getCountryName()`) aren't ported; the `:lang`
 *    argument is accepted but ignored, same raw value returned regardless.
 *  - `isp`/`operator`/`carrier`/`connection_type` — no real data source
 *    (IP2Location LITE tier has none, see Phase 9's finding) — always
 *    empty string, matching legacy's own `?: ""` fallback shape for an
 *    unresolved field, not a fabricated "not detected" claim.
 *  - (`is_using_proxy` used to live here as "no runtime exists, always
 *    0" — real now too, see the ported-list note above, same as `is_bot`.)
 *  - `visitor_code`, `destination` — not currently exposed on `Payload`
 *    by any earlier stage.
 *  - `from_file`, `sample`, and custom (admin-defined) macros — the
 *    custom-macro system (`Component\Macros\Repository\
 *    CustomMacroRepository`) lets an admin register arbitrary PHP code
 *    as a macro; same class of risk as `local_file`'s PHP execution,
 *    deliberately out of scope for this pass.
 *  - Conversion-context macros (`tid`/`status`/`original_status`/
 *    `conversion_time`/`conversion_revenue`/`conversion_cost`/
 *    `conversion_profit`) — irrelevant here, a click has no conversion
 *    yet; see `TrafficCore\Postback\OutboundPostbackService` for the
 *    separate, smaller conversion-context macro set used there.
 *  - `alwaysRaw()` macro override (legacy forces certain macros, e.g.
 *    `debug`, to raw/un-urlencoded output regardless of the `{_name}`
 *    prefix) — not ported; use the `{_debug}` raw-prefix form to get
 *    the same effect.
 */
class ClickMacroValues
{
    /** @return array<string,string|null> */
    public static function forPayload(Payload $payload): array
    {
        $rawClick = $payload->rawClick;
        $fields = $payload->clickFields;
        $geo = $payload->geoDevice['geo'] ?? [];
        $device = $payload->geoDevice['device'] ?? [];

        $cost = (float) ($rawClick['cost'] ?? 0);
        $revenue = (float) ($rawClick['lead_revenue'] ?? 0)
            + (float) ($rawClick['sale_revenue'] ?? 0)
            + (float) ($rawClick['rejected_revenue'] ?? 0);

        $macros = [
            'sub_id' => $fields['sub_id'] ?? null,
            'subid' => $fields['sub_id'] ?? null,
            'source' => $fields['source'] ?? null,
            'referrer' => $fields['referrer'] ?? null,
            'referer' => $fields['referrer'] ?? null,
            'search_engine' => $fields['search_engine'] ?? null,
            'se' => $fields['search_engine'] ?? null,
            'keyword' => $fields['keyword'] ?? null,
            'ad_campaign_id' => $fields['ad_campaign_id'] ?? null,
            'creative_id' => $fields['creative_id'] ?? null,
            'external_id' => $fields['external_id'] ?? null,
            'x_requested_with' => $fields['x_requested_with'] ?? null,
            'cost' => (string) $cost,
            'revenue' => (string) $revenue,
            'profit' => (string) ($revenue - $cost),
            'campaign_id' => isset($payload->campaign['id']) ? (string) $payload->campaign['id'] : null,
            'tds_campaign_id' => isset($payload->campaign['id']) ? (string) $payload->campaign['id'] : null,
            'campaign_name' => $payload->campaign['name'] ?? null,
            'tds_campaign_name' => $payload->campaign['name'] ?? null,
            'stream_id' => isset($payload->stream['id']) ? (string) $payload->stream['id'] : null,
            'landing_id' => $payload->landingId !== null ? (string) $payload->landingId : null,
            'tds_landing_id' => $payload->landingId !== null ? (string) $payload->landingId : null,
            'offer_id' => $payload->offerId !== null ? (string) $payload->offerId : null,
            'tds_offer_id' => $payload->offerId !== null ? (string) $payload->offerId : null,
            'parent_campaign_id' => $payload->parentCampaignId !== null ? (string) $payload->parentCampaignId : null,
            'country' => $geo['country'] ?? '',
            'country_code' => $geo['country'] ?? '',
            'country_name' => $geo['country'] ?? '',
            'region' => $geo['region'] ?? '',
            'region_name' => $geo['region'] ?? '',
            'city' => $geo['city'] ?? '',
            'device_type' => $device['device_type'] ?? '',
            'device_model' => $device['device_model'] ?? '',
            'browser' => $device['browser'] ?? '',
            'browser_version' => $device['browser_version'] ?? '',
            'os' => $device['os'] ?? '',
            'os_version' => $device['os_version'] ?? '',
            'isp' => '',
            'operator' => '',
            'carrier' => '',
            'connection_type' => '',
            'is_bot' => (string) (int) ($rawClick['is_bot'] ?? 0),
            'is_using_proxy' => (string) (int) ($rawClick['is_using_proxy'] ?? 0),
            'ip' => $payload->signal['ip'] ?? '',
            'user_agent' => $payload->signal['userAgent'] ?? '',
            'ua' => $payload->signal['userAgent'] ?? '',
            'useragent' => $payload->signal['userAgent'] ?? '',
            'language' => $payload->signal['language'] ?? '',
            'current_domain' => self::currentDomain($payload),
            'date' => gmdate('c'),
            'random' => (string) random_int(0, 9999),
            'token' => $payload->lookupToken,
            'currency' => self::setting('currency') ?? '',
            'debug' => self::debug($payload),
        ];

        for ($i = 1; $i <= 15; $i++) {
            $macros['sub_id_' . $i] = $fields['sub_id_' . $i] ?? null;
        }

        for ($i = 1; $i <= 10; $i++) {
            $macros['extra_param_' . $i] = $fields['extra_param_' . $i] ?? null;
        }

        return $macros;
    }

    private static function currentDomain(Payload $payload): string
    {
        $uri = $payload->request->getUri();

        return $uri->getScheme() . '://' . $uri->getHost();
    }

    private static function debug(Payload $payload): string
    {
        return (string) json_encode([
            'headers' => $payload->request->getHeaders(),
            'server_params' => $payload->request->getServerParams(),
            'click' => $payload->rawClick,
            'method' => $payload->request->getMethod(),
            'uri' => (string) $payload->request->getUri(),
        ], JSON_PRETTY_PRINT);
    }

    private static function setting(string $key): ?string
    {
        $stmt = Db::instance()->prepare('SELECT value FROM settings WHERE `key` = ? LIMIT 1');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}
