<?php

namespace TrafficCore\Pipeline\Device;

use DeviceDetector\DeviceDetector;
use DeviceDetector\Parser\Device\AbstractDeviceParser;

/**
 * Port of legacy `Traffic\Device\Service\DeviceInfoService`
 * (application/Traffic/Device/Service/DeviceInfoService.php) — UA
 * parsing via the official `matomo/device-detector` package (the
 * current Packagist name for the library legacy vendors under the old
 * `piwik/device-detector` name — same `DeviceDetector\...` namespace and
 * top-level API: `setUserAgent()`/`parse()`/`getClient()`/`getOs()`/
 * `getDevice()`/`getBrandName()`/`getModel()`, confirmed unchanged by
 * reading the installed 6.5.1 source).
 *
 * Ported faithfully:
 *  - `setVersionTruncation(VERSION_TRUNCATION_NONE)` (legacy keeps full
 *    version strings, not the library's truncated default).
 *  - `_convertOs()` — the `Mac` -> `OS X`, `MTK / Nucleus` -> `MTK`,
 *    `PlayStation Portable` -> `PS`, `PS Portable` -> `PS`, `Nintendo
 *    Mobile` -> `Nintendo` replacement table, verbatim.
 *  - `_convertDeviceModel()` — brand prefix except for
 *    `["Apple", "RIM"]`, concatenated with the model.
 *  - `_convertDeviceType()` — phablet/feature_phone normalized to
 *    smartphone before matching id->name, then the Android/"Phone"/
 *    "Windows CE"/"Symbian OS" OS-name and "Mobile"/"Mini" client-name
 *    mobile-detection fallbacks when no device-type id matched at all.
 *
 * One legacy branch deliberately NOT carried over, found while reading
 * `_convertDeviceType()` closely (per this project's "verify by reading,
 * don't assume" convention): legacy's `$_deviceTypeReplacements =
 * ["smartphone"=>"mobile","feature_phone"=>"mobile","phablet"=>"mobile"]`
 * is applied to `$deviceType` *after* `array_search()` against
 * `$_matches`, but `$_matches`'s keys are already normalized
 * `Traffic\Device\DeviceType` string constants ("desktop", "mobile",
 * "tablet", ...) — none of which is literally "smartphone",
 * "feature_phone" or "phablet". That replacement table can therefore
 * never match anything the preceding `array_search()` could produce; it
 * is dead code in legacy itself. Not ported here — there is no live
 * behavior to reproduce.
 *
 * NOT ported: `is_bot` (bot detection is a separate, not-yet-ported
 * concern in this pipeline; `_findDeviceInfo()`'s WIFI-default-for-
 * mobile-without-connection-type side effect — connection_type is not
 * resolved anywhere in traffic-core, see VisitorResolver's docblock);
 * `device_brand` — legacy stores it as a plain `RawClick` field but
 * `visitors` has no `device_brand` column (confirmed via `DESCRIBE`),
 * so it is legitimately dropped at the storage layer in legacy too, not
 * a gap introduced here.
 */
class DeviceInfoResolver
{
    private const EXCEPT_MANUFACTURERS = ['Apple', 'RIM'];

    private const OS_REPLACEMENTS = [
        'Mac' => 'OS X',
        'MTK / Nucleus' => 'MTK',
        'PlayStation Portable' => 'PS',
        'PS Portable' => 'PS',
        'Nintendo Mobile' => 'Nintendo',
    ];

    /** Traffic\Device\DeviceType constant value => AbstractDeviceParser::DEVICE_TYPE_* id. */
    private const MATCHES = [
        'desktop' => AbstractDeviceParser::DEVICE_TYPE_DESKTOP,
        'mobile' => AbstractDeviceParser::DEVICE_TYPE_SMARTPHONE,
        'tablet' => AbstractDeviceParser::DEVICE_TYPE_TABLET,
        'console' => AbstractDeviceParser::DEVICE_TYPE_CONSOLE,
        'tv' => AbstractDeviceParser::DEVICE_TYPE_TV,
        'car_browser' => AbstractDeviceParser::DEVICE_TYPE_CAR_BROWSER,
        'smart_display' => AbstractDeviceParser::DEVICE_TYPE_SMART_DISPLAY,
        'camera' => AbstractDeviceParser::DEVICE_TYPE_CAMERA,
        'portable_media_player' => AbstractDeviceParser::DEVICE_TYPE_PORTABLE_MEDIA_PAYER,
    ];

    private ?DeviceDetector $detector = null;

    /**
     * @return array{browser: ?string, browser_version: ?string, os: ?string, os_version: ?string, device_type: ?string, device_model: ?string}
     */
    public function resolve(string $userAgent): array
    {
        $empty = [
            'browser' => null,
            'browser_version' => null,
            'os' => null,
            'os_version' => null,
            'device_type' => null,
            'device_model' => null,
        ];

        if (trim($userAgent) === '') {
            return $empty;
        }

        $detector = $this->detector();

        try {
            $detector->setUserAgent($userAgent);
            $detector->parse();
        } catch (\Throwable $e) {
            // Malformed UA must never break the click pipeline.
            return $empty;
        }

        $browser = $detector->getClient() ?: [];
        $os = $detector->getOs() ?: [];

        return [
            'browser' => $this->nullIfEmpty($browser['name'] ?? null),
            'browser_version' => $this->nullIfEmpty($browser['version'] ?? null),
            'os' => $this->nullIfEmpty($this->convertOs($os['name'] ?? null)),
            'os_version' => $this->nullIfEmpty($os['version'] ?? null),
            'device_type' => $this->nullIfEmpty($this->convertDeviceType($detector, $os, $browser)),
            'device_model' => $this->nullIfEmpty($this->convertDeviceModel($detector->getBrandName(), $detector->getModel())),
        ];
    }

    private function detector(): DeviceDetector
    {
        if ($this->detector === null) {
            AbstractDeviceParser::setVersionTruncation(AbstractDeviceParser::VERSION_TRUNCATION_NONE);
            $this->detector = new DeviceDetector();
            $this->detector->discardBotInformation();
            $this->detector->skipBotDetection();
        }

        return $this->detector;
    }

    private function convertDeviceModel(string $deviceBrand, string $deviceModel): string
    {
        $name = '';
        if ($deviceBrand !== '') {
            if (!in_array($deviceBrand, self::EXCEPT_MANUFACTURERS, true)) {
                $name = $deviceBrand;
            }
            if ($deviceModel !== '') {
                if ($name !== '') {
                    $name .= ' ';
                }
                $name .= $deviceModel;
            }
        }

        return $name;
    }

    private function convertOs(?string $os): ?string
    {
        if ($os === null) {
            return null;
        }

        return self::OS_REPLACEMENTS[$os] ?? $os;
    }

    /**
     * @param array<string,mixed> $os
     * @param array<string,mixed> $client
     */
    private function convertDeviceType(DeviceDetector $dd, array $os, array $client): ?string
    {
        $id = $dd->getDevice();

        if ($id === AbstractDeviceParser::DEVICE_TYPE_PHABLET) {
            $id = AbstractDeviceParser::DEVICE_TYPE_SMARTPHONE;
        }
        if ($id === AbstractDeviceParser::DEVICE_TYPE_FEATURE_PHONE) {
            $id = AbstractDeviceParser::DEVICE_TYPE_SMARTPHONE;
        }

        $deviceType = null;
        if ($id !== null) {
            $found = array_search($id, self::MATCHES, true);
            $deviceType = $found !== false ? $found : null;
        }

        if ($deviceType === null) {
            $osName = (string) ($os['name'] ?? '');
            $clientName = (string) ($client['name'] ?? '');

            if (strpos($osName, 'Android') !== false
                || stripos($osName, 'Phone') !== false
                || $osName === 'Windows CE'
                || $osName === 'Symbian OS'
            ) {
                $deviceType = 'mobile';
            }
            if (strpos($clientName, 'Mobile') !== false || strpos($clientName, 'Mini') !== false) {
                $deviceType = 'mobile';
            }
        }

        return $deviceType;
    }

    private function nullIfEmpty(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
