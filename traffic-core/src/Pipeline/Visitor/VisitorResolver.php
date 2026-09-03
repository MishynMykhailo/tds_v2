<?php

namespace TrafficCore\Pipeline\Visitor;

use TrafficCore\Db;

/**
 * Port of legacy `Traffic\Service\VisitorService::generateCode()` +
 * `Component\Clicks\ClickProcessing\ExtractVisitors\VisitorAggregator`
 * (application/Traffic/Service/VisitorService.php,
 * application/Component/Clicks/ClickProcessing/ExtractVisitors/
 * VisitorAggregator.php) — the real find-or-create Visitor lookup that
 * `BuildRawClickStage` needs instead of `random_int(1, PHP_INT_MAX)`.
 *
 * `generateCode()` ported field-for-field:
 * `ipString . userAgent . connectionType . country . city . deviceModel`,
 * hashed. Legacy calls a custom `murmurhash3()` function (not visible in
 * this port's environment); per this task's instructions, PHP's builtin
 * `hash('murmur3a', $string)` is used instead — an acceptable equivalent
 * for a non-cryptographic, deterministic dedup key. `connectionType` is
 * always empty string here (traffic-core resolves no ISP/connection_type
 * data at all — see class docblock below and GeoDbResolver's docblock),
 * which only narrows the hash input versus legacy, it does not change
 * the hashing approach.
 *
 * `VisitorAggregator`'s actual persistence (`Core\Db\Db::multiInsert()`
 * after `loadIds()` finds existing rows by `visitor_code`) is a
 * batch-oriented two-phase find-then-bulk-insert designed for many
 * clicks at once. This port only ever handles one click per request, so
 * it collapses to a single atomic
 * `INSERT ... ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)` against
 * `visitors.visitor_code` (same idiom `DictionaryRepository` uses for
 * every `ref_*` table) — same find-or-create outcome, no batching
 * complexity needed for a batch size of one.
 *
 * Deliberate, permanent gap (not a Phase-N deferral — there is no real
 * data source, confirmed by inspecting the entire legacy
 * `application/Component/GeoDb/*` tree): `isp_id`, `operator_id`, and
 * `connection_type_id` are always left null. The only real GeoDb file in
 * the legacy repo is the IP2Location LITE DB3 tier
 * (`GeoDbResolver`'s docblock), which has no ISP/carrier data; every
 * other provider directory (`Maxmind`, `Sypex`, `ProIP`, `Tds`) is code
 * with no matching `.BIN`/data file anywhere in the repo.
 */
class VisitorResolver
{
    public function __construct(private DictionaryRepository $dictionaries = new DictionaryRepository())
    {
    }

    /**
     * @param array{country: ?string, region: ?string, city: ?string} $geo
     * @param array{browser: ?string, browser_version: ?string, os: ?string, os_version: ?string, device_type: ?string, device_model: ?string} $device
     */
    public function resolve(string $ip, string $userAgent, array $geo, array $device, string $language = ''): int
    {
        $visitorCode = $this->generateCode($ip, $userAgent, $geo, $device);

        $ipId = $this->dictionaries->findOrCreateByValue('ref_ips', $this->packIp($ip));
        $userAgentId = $this->dictionaries->findOrCreateByValue('ref_user_agents', $userAgent);

        // `visitors.ip_id`/`user_agent_id` are NOT NULL — an IP that
        // can't be packed (IPv6; see packIp()) or an empty UA still needs
        // a row. Fall back to the "unknown" sentinel (packed 0 / empty
        // string) rather than aborting visitor resolution, since a click
        // must never be lost over GeoDb/device concerns.
        $ipId ??= $this->dictionaries->findOrCreateByValue('ref_ips', 0);
        // allowEmptyString: true — an empty UA must still resolve to a
        // REAL row (see DictionaryRepository::findOrCreateByValue()'s
        // docblock; without this flag this fallback was a silent no-op,
        // found live via an uncaught NOT NULL PDOException).
        $userAgentId ??= $this->dictionaries->findOrCreateByValue('ref_user_agents', '', true);

        $countryId = $this->dictionaries->findOrCreateByValue('ref_countries', $geo['country'] ?? null);
        $regionId = $this->dictionaries->findOrCreateByValue('ref_regions', $geo['region'] ?? null);
        $cityId = $this->dictionaries->findOrCreateByValue('ref_cities', $geo['city'] ?? null);
        $deviceTypeId = $this->dictionaries->findOrCreateByValue('ref_device_types', $device['device_type'] ?? null);
        $deviceModelId = $this->dictionaries->findOrCreateByValue('ref_device_models', $device['device_model'] ?? null);
        $languageId = $this->dictionaries->findOrCreateByValue('ref_languages', $language !== '' ? $language : null);
        $browserId = $this->dictionaries->findOrCreateByValue('ref_browsers', $device['browser'] ?? null);
        $browserVersionId = $this->dictionaries->findOrCreateByValue('ref_browser_versions', $device['browser_version'] ?? null);
        $osId = $this->dictionaries->findOrCreateByValue('ref_os', $device['os'] ?? null);
        $osVersionId = $this->dictionaries->findOrCreateByValue('ref_os_versions', $device['os_version'] ?? null);

        $pdo = Db::instance();
        $stmt = $pdo->prepare(
            'INSERT INTO visitors
                (visitor_code, ip_id, user_agent_id, country_id, region_id, city_id,
                 device_type_id, device_model_id, language_id, browser_id, browser_version_id,
                 os_id, os_version_id, connection_type_id, operator_id, isp_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $stmt->execute([
            $visitorCode,
            $ipId,
            $userAgentId,
            $countryId,
            $regionId,
            $cityId,
            $deviceTypeId,
            $deviceModelId,
            $languageId,
            $browserId,
            $browserVersionId,
            $osId,
            $osVersionId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array{country: ?string, region: ?string, city: ?string} $geo
     * @param array{device_model: ?string} $device
     */
    private function generateCode(string $ip, string $userAgent, array $geo, array $device): string
    {
        $connectionType = ''; // always empty — see class docblock.
        $src = $ip
            . $userAgent
            . $connectionType
            . ($geo['country'] ?? '')
            . ($geo['city'] ?? '')
            . ($device['device_model'] ?? '');

        return hash('murmur3a', $src);
    }

    /**
     * Packs an IPv4 string into the unsigned int `ref_ips.value` expects
     * (legacy's `ip2long`-style storage — confirmed via the migration's
     * docblock). Returns null for anything `ip2long()` can't parse
     * (empty string, IPv6) — the caller falls back to a `0` sentinel row
     * since `visitors.ip_id` is NOT NULL (see resolve()).
     */
    private function packIp(string $ip): ?int
    {
        if ($ip === '') {
            return null;
        }

        $long = ip2long($ip);
        if ($long === false) {
            return null; // IPv6, or not a valid IPv4 string.
        }

        // sprintf('%u', ...) turns PHP's signed 32-bit ip2long() result
        // into the unsigned representation the column stores.
        return (int) sprintf('%u', $long);
    }
}
