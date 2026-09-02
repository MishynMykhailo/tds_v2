<?php

namespace TrafficCore\Pipeline\GeoDb;

/**
 * Port of legacy `Component\GeoDb\Ip2Location\Ip2LocationDb3Lite` +
 * `Ip2LocationAdapter` (application/Component/GeoDb/Ip2Location/
 * Ip2LocationDb3Lite.php, Ip2LocationAdapter.php) — the only GeoDb
 * provider with a real, physically-present `.BIN` data file anywhere in
 * the legacy repo (`var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN`,
 * confirmed 48MB, IP2Location LITE DB3 tier — country/region/city only).
 * Every other provider under `application/Component/GeoDb/{Maxmind,
 * Sypex,ProIP,Tds}` is code with no matching data file and was not
 * ported — there is nothing to wrap.
 *
 * Uses the official `ip2location/ip2location-php` package (same
 * `\IP2Location\Database` class legacy's adapter wraps directly).
 * Pinned to `^8.2` (matching the exact 8.2.3 build already vendored in
 * legacy) rather than the current 9.x line: 9.x hard-requires the
 * `bcmath` PHP extension in its composer.json, which is not installed
 * in `tds2-php-dev` (nor, per legacy's own adapter code checking
 * `extension_loaded("bcmath")` before warning, guaranteed in legacy's
 * runtime either) — 8.2.x/8.3.x declare no extension requirements at
 * all and behave identically for the IPv4-only lookups this class does.
 *
 * Deliberate deviations from legacy's adapter, documented per this
 * project's "never silently assume" convention:
 *
 *  - `region` is returned as IP2Location's raw `regionName` (e.g.
 *    "California"), NOT legacy's compact `"US_CA"` code. Legacy's
 *    `_getRegionCode()` builds that code via a per-country reverse
 *    lookup dictionary — 247 individual PHP files under
 *    `application/Traffic/GeoDb/ip2location_reverse/*.php` (confirmed by
 *    listing that directory) mapping full region names to short codes.
 *    Porting all 247 dictionaries is out of scope for this task; the
 *    full region name is stored in `ref_regions.value` instead. This is
 *    a real, intentional accuracy/format difference, not a bug — flagged
 *    to the coordinator as a follow-up if the exact legacy code format
 *    is ever needed by a report or filter.
 *  - ISP is NOT resolved here (`isp_id` stays null downstream) — the
 *    LITE DB3 tier has no ISP field. Legacy's adapter reads
 *    `$record["isp"]` regardless of tier; against this actual .BIN file
 *    it always resolves to IP2Location's `FIELD_NOT_SUPPORTED` sentinel,
 *    i.e. null in practice too — this is not a capability we are
 *    dropping, legacy already gets nothing real here against this file.
 *  - No `bcmath`-dependent IPv6 warning/exception path (legacy's
 *    `_wrapInvalidIp()` throws in non-production when bcmath is missing
 *    and the lookup hits `INVALID_IP_ADDRESS`). Here, any invalid/
 *    unsupported IP (including IPv6, which this LITE DB3 file cannot
 *    resolve at all — it is an IPv4-only .BIN) simply yields null
 *    fields — this must never break a click that has nothing to do with
 *    GeoDb.
 */
class GeoDbResolver
{
    private ?\IP2Location\Database $db = null;
    private bool $dbLoadAttempted = false;
    private string $filePath;

    public function __construct(?string $filePath = null)
    {
        $this->filePath = $filePath
            ?? getenv('GEODB_IP2LOCATION_PATH')
            ?: (__DIR__ . '/../../../var/geoip/IP2LOCATION-LITE-DB3.BIN');
    }

    /**
     * @return array{country: ?string, region: ?string, city: ?string}
     */
    public function resolve(string $ip): array
    {
        $empty = ['country' => null, 'region' => null, 'city' => null];

        if ($ip === '') {
            return $empty;
        }

        $db = $this->database();
        if ($db === null) {
            return $empty;
        }

        try {
            $record = $db->lookup($ip, \IP2Location\Database::ALL);
        } catch (\Throwable $e) {
            // Malformed IP, corrupt record, etc. — GeoDb failure must never
            // break the click pipeline (see class docblock).
            return $empty;
        }

        if (!is_array($record)) {
            return $empty;
        }

        return [
            'country' => $this->cleanValue($record['countryCode'] ?? null),
            'region' => $this->cleanValue($record['regionName'] ?? null),
            'city' => $this->cleanValue($record['cityName'] ?? null),
        ];
    }

    private function database(): ?\IP2Location\Database
    {
        if ($this->dbLoadAttempted) {
            return $this->db;
        }
        $this->dbLoadAttempted = true;

        if (!is_file($this->filePath)) {
            // No GeoDb file available — graceful null resolution, no crash.
            return null;
        }

        try {
            $this->db = new \IP2Location\Database($this->filePath, \IP2Location\Database::FILE_IO);
        } catch (\Throwable $e) {
            $this->db = null;
        }

        return $this->db;
    }

    /**
     * Mirrors legacy `Ip2LocationAdapter::_wrapInvalidIp()`'s sentinel
     * handling, minus the bcmath warning/exception (see class docblock):
     * IP2Location returns human-readable sentinel strings instead of
     * null for "field not in this tier" / "invalid IP" — normalize both
     * to null.
     */
    private function cleanValue(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        if ($value === \IP2Location\Database::FIELD_NOT_SUPPORTED) {
            return null;
        }
        if ($value === \IP2Location\Database::INVALID_IP_ADDRESS) {
            return null;
        }
        if ($value === '-') {
            return null;
        }

        return $value;
    }
}
