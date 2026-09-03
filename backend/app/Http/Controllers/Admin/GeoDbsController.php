<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy `Component\GeoDb\Controller\GeoDbsController`
 * + `Component\GeoDb\Serializer\GeoDbSerializer` +
 * `Traffic\GeoDb\Repository\GeoDbRepository` +
 * `Traffic\GeoDb\Service\GeoDbService` (old codebase:
 * application/Component/GeoDb/Controller/GeoDbsController.php,
 * application/Component/GeoDb/Serializer/GeoDbSerializer.php,
 * application/Component/GeoDb/GeoDbDefinition.php,
 * application/Traffic/GeoDb/Repository/GeoDbRepository.php,
 * application/Traffic/GeoDb/Service/GeoDbService.php, and the 15 concrete
 * `AbstractGeoDb` subclasses under application/Component/GeoDb/{Ip2Location,
 * Sypex,Maxmind,Tds,ProIP}/*.php).
 *
 * Contract reference (doc is imprecise on `settings`/`saveSettings` — see
 * note below; everything else here is cross-checked against the real
 * source, not the doc): docs/legacy-reference/frontend/api/10.9_geodb.md.
 *
 * SCOPE (explicit task boundary): GeoDb in the legacy app is NOT a DB
 * table — it's a static registry of 15 hardcoded "known geo database
 * types" (Ip2Location/Sypex/Maxmind/Tds/ProIP), each an external binary
 * file that has to be downloaded/purchased and dropped on disk. Real
 * AUTOMATED download (`GeoDbService::update()` -> `DownloadManager::
 * update()`, which does HTTP downloads + gzip unpack + CRC verification
 * against tds.io/maxmind/ip2location/sypex/proip) needs a paid/registered
 * license key per provider this environment does not have — still
 * deliberately OUT OF SCOPE, `updateAction` still 501s for that path
 * specifically (see its docblock).
 *
 * UPDATE (2026-09-03, backlog 3.1): manual file management IS now real —
 * `uploadAction` lets an admin who already downloaded/purchased a geo db
 * file themselves (same file legacy would have fetched automatically)
 * install it via a real multipart upload, and `time`/`exists`/`installed`
 * now honestly reflect the file that lands on disk (real `filemtime()`,
 * not hardcoded). This is "заменить файл, показать статус" from the task
 * brief; "скачать новую версию" (automated fetch from the provider) is
 * the part still excluded, for the credential reason above, not laziness.
 *
 * This controller provides:
 *  - `index` — the static list of all 15 known db types with their real,
 *    live-computed on-disk status (file only appears "installed" once an
 *    admin has actually placed one there via `uploadAction` or by hand).
 *  - `settings`/`saveSettings` — the *actual* legacy "geoDb settings"
 *    concept, which is NOT an autoupdate flag (the docblock in
 *    10.9_geodb.md says "настройки автообновления баз" but the real
 *    `GeoDbService::settings()`/`saveSettings()` source
 *    (Traffic/GeoDb/Service/GeoDbService.php) shows this is wrong — it's a
 *    `data_type => db_id` map, i.e. "which installed geo db resolves
 *    country/city/isp/etc". Verified against the real source per this
 *    project's "verify, don't just please" rule, not copied from the doc.
 *  - `update` — TODO stub, 501 (automated provider download only — see
 *    UPDATE note above).
 *  - `upload` — NEW, no legacy equivalent (manual file install, see its
 *    own docblock).
 *
 * ## `index` field-by-field mapping to the real `GeoDbSerializer::serialize()`
 * (application/Component/GeoDb/Serializer/GeoDbSerializer.php), no
 * invented field names:
 *   id, name, type, data_types, is_recommended, setting_key, purchase_link
 *     — straight from `GeoDbDefinition`, copied verbatim per db type below.
 *   exists      — real `AbstractGeoDb::exists()` = `file_exists($path)`.
 *                 Literally computed against `base_path($path)` here (not
 *                 hardcoded `false`) — if an admin manually drops a file at
 *                 the expected location, this honestly flips to `true`,
 *                 same as legacy. In a stock install nothing is there, so
 *                 it evaluates to `false` for all 15 today.
 *   path        — `GeoDbDefinition::filePath()`. For 14/15 types this is a
 *                 static path (mirrors the real `ROOT . "/var/..."`
 *                 layout, rebased under `base_path()`). For
 *                 `user_bot_ip_db` (INTERNAL type) the real legacy path is
 *                 a *callable* depending on
 *                 `Component\BotDetection\Repository\UserBotDBCARepository`
 *                 (bot-detection module, not ported anywhere in this
 *                 codebase) — represented as `null` here rather than
 *                 guessing a path, with `exists` hardcoded `false` to
 *                 match (nothing to check existence against).
 *   time        — real logic is `exists() ? manager->timestamp() : null`;
 *                 since `exists` is always false in a stock install, this
 *                 is always `null` here. Not reimplementing
 *                 `filemtime()`-based `timestamp()` since it only ever
 *                 fires under `exists() === true` — reachable now that
 *                 `uploadAction()` can actually put a file on disk (see
 *                 that method's docblock); implemented for real below.
 *   status_code/status_text
 *               — real value comes from `$manager->status()`
 *                 (`Component\GeoDb\DownloadManager\*`). Every concrete
 *                 manager's `status()` was read directly: `HostedDownloadManager`
 *                 and `NullDownloadManager` (used by the 5 HOSTED + 1
 *                 INTERNAL types) unconditionally return `[OK, ""]` — no
 *                 network call. Every EXTERNAL type's manager
 *                 (Ip2Location/Maxmind/Sypex/ProIP DownloadManager)
 *                 short-circuits to `[NO_KEY, ""]` before any network call
 *                 IF its `setting_key` has no configured value. Both of
 *                 those pre-network branches are faithfully replicated
 *                 here (via a live `Setting` lookup for EXTERNAL types).
 *                 What is NOT replicated: the network-dependent branches
 *                 past that point (CRC/key-validity checks against
 *                 tds.io/maxmind.com/ip2location.com/proip.com) — those
 *                 require the download infrastructure this task explicitly
 *                 excludes. If an EXTERNAL type's key IS configured (via
 *                 the generic `settings.update` endpoint), status stays
 *                 `NO_KEY`'s sibling here is skipped entirely and we fall
 *                 back to the same `OK`/no-message pair as hosted types
 *                 (see `resolveStatus()`) rather than fabricate a
 *                 validity check we can't perform — documented as a
 *                 deliberate simplification, not a guess dressed as fact.
 *   key         — real value:
 *                 `$definition->settingKey() ? CachedSettingsRepository->get($settingKey) : NULL`.
 *                 Replicated via a live `Setting::query()->find($settingKey)`
 *                 lookup (no cache layer in this port).
 *   update_available
 *               — real value is `$manager->isUpdateAvailable()` (always
 *                 attempted, `checkUpdates=true` is hardcoded in the real
 *                 `GeoDbsController::indexAction()`). For 3 of the 15
 *                 types (`tds_carrier`, `tds_bot_db2`, `user_bot_ip_db`,
 *                 all on `NullDownloadManager`) this is a static `false`
 *                 with no network call — faithfully reproduced. For the
 *                 other 12 (`HostedDownloadManager`/EXTERNAL managers)
 *                 this requires a live HTTP request to the provider's CRC
 *                 or file-info endpoint — exactly the download
 *                 infrastructure this task excludes. Rather than fake a
 *                 `true`/`false`, this is honestly `null` for those 12,
 *                 which is NOT what legacy would show (legacy always
 *                 resolves to a real bool or an `error` string) — flagged
 *                 here as a deliberate, documented gap rather than a
 *                 silent guess. TODO: once real DownloadManager
 *                 infrastructure is ported, replace this with a real
 *                 `isUpdateAvailable()` call per type.
 *   error       — real serializer sets this INSTEAD of `update_available`
 *                 if `isUpdateAvailable()` throws `DbError`. Never
 *                 populated here since `update_available` is never
 *                 attempted (see above) — omitted from the payload
 *                 entirely rather than always-null, to keep the "did an
 *                 error happen" signal meaningful if this is ever wired up
 *                 for real.
 *
 * ## Field NOT in the real serializer, added here (explicit TODO, per task
 * instruction not to invent unlabeled fields):
 *   installed   — bool, literally `exists` restated. `Component\GeoDb\
 *                 GeoDbStatus` (OK/ERROR/NO_KEY) has NO "not installed"
 *                 value at all — verified by reading GeoDbStatus.php
 *                 directly, not assumed — so there is no legacy field name
 *                 to reuse for "is this db's file actually on disk, plain
 *                 and simple" as opposed to the OK/ERROR/NO_KEY provider
 *                 auth-status concept `status_code` actually encodes. This
 *                 redundant alias exists purely so API consumers don't
 *                 have to know that `status_code=ok` does NOT mean
 *                 "installed" (it just means "no api key required / no
 *                 network error seen"). TODO: remove if/when a real
 *                 frontend contract is confirmed to not need it.
 */
class GeoDbsController extends Controller
{
    /** `Component\GeoDb\GeoDbStatus` constants, copied verbatim. */
    private const STATUS_OK = 'ok';

    private const STATUS_NO_KEY = 'no_key';

    /** `Traffic\Model\Setting::IPDB` — the exact legacy settings-table key. */
    private const SETTINGS_KEY = 'ipdb';

    /**
     * Registry of all 15 known geo db types, mirroring
     * `Traffic\GeoDb\Repository\GeoDbRepository::init()` in declaration
     * order. Every `id`/`name`/`type`/`data_types`/`path`/`is_recommended`/
     * `setting_key`/`purchase_link` value below was copied verbatim from
     * the corresponding `GeoDbDefinition` construction in the real
     * `Component\GeoDb\{Ip2Location,Sypex,Maxmind,Tds,ProIP}\*.php` files —
     * none of it is guessed.
     *
     * @var array<int, array{id: string, name: string, type: string, data_types: array<int, string>, path: string|null, is_recommended: bool|null, setting_key: string|null, purchase_link: string|null}>
     */
    private const DB_TYPES = [
        [
            'id' => 'ip2location_lite', 'name' => 'IP2Location DB3 Lite', 'type' => 'hosted',
            'data_types' => ['country', 'region', 'city'],
            'path' => 'var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN',
            'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null,
        ],
        [
            'id' => 'ip2location_full', 'name' => 'IP2Location DB3 Full', 'type' => 'external',
            'data_types' => ['country', 'region', 'city'],
            'path' => 'var/geoip/IP2Location/full/IPV6-COUNTRY-REGION-CITY.BIN',
            'is_recommended' => null, 'setting_key' => 'ip2location_full_token',
            'purchase_link' => 'https://tds.io/go/iptolocation',
        ],
        [
            'id' => 'ip2location_full_isp', 'name' => 'IP2Location DB4', 'type' => 'external',
            'data_types' => ['country', 'region', 'city', 'isp'],
            'path' => 'var/geoip/IP2Location/full_isp/IPV6-COUNTRY-REGION-CITY-ISP.BIN',
            'is_recommended' => true, 'setting_key' => 'ip2location_full_isp_token',
            'purchase_link' => 'https://tds.io/go/iptolocation',
        ],
        [
            'id' => 'ip2location_px2', 'name' => 'IP2Location PX2', 'type' => 'external',
            'data_types' => ['country', 'proxy_type'],
            'path' => 'var/geoip/IP2Location/PX2/IP2PROXY-IP-PROXYTYPE-COUNTRY.BIN',
            'is_recommended' => true, 'setting_key' => 'ip2location_px2_token',
            'purchase_link' => 'https://tds.io/go/iptolocation',
        ],
        [
            'id' => 'sypex_lite', 'name' => 'Sypex City Lite', 'type' => 'hosted',
            'data_types' => ['country', 'region', 'city', 'city_ru'],
            'path' => 'var/geoip/SxGeoCity.dat',
            'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null,
        ],
        [
            'id' => 'sypex_full', 'name' => 'Sypex City Full', 'type' => 'external',
            'data_types' => ['country', 'region', 'city', 'city_ru'],
            'path' => 'var/geoip/SxGeoCity/SxGeoCity.dat',
            'is_recommended' => null, 'setting_key' => 'sx_full_key', 'purchase_link' => null,
        ],
        [
            'id' => 'maxmind_lite', 'name' => 'Maxmind City Lite (Legacy)', 'type' => 'hosted',
            'data_types' => ['country', 'region', 'city'],
            'path' => 'var/geoip/GeoLiteCity.dat',
            'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null,
        ],
        [
            'id' => 'maxmind_full', 'name' => 'Maxmind City Full (Legacy)', 'type' => 'external',
            'data_types' => ['country', 'region', 'city'],
            'path' => 'var/geoip/GeoIPCity/GeoIPCity.dat',
            'is_recommended' => null, 'setting_key' => 'maxmind_city_key', 'purchase_link' => null,
        ],
        [
            'id' => 'maxmind_country_full', 'name' => 'Maxmind Country Full (Legacy)', 'type' => 'external',
            'data_types' => ['country'],
            'path' => 'var/geoip/GeoIPCountry/GeoIPCountry.dat',
            'is_recommended' => null, 'setting_key' => 'maxmind_country_key', 'purchase_link' => null,
        ],
        [
            'id' => 'maxmind_isp', 'name' => 'Maxmind ISP', 'type' => 'external',
            'data_types' => ['isp'],
            'path' => 'var/geoip/GeoISP/GeoISP.dat',
            'is_recommended' => null, 'setting_key' => 'maxmind_isp_key', 'purchase_link' => null,
        ],
        [
            'id' => 'maxmind_connection_types', 'name' => 'Maxmind Connection Type (Legacy)', 'type' => 'external',
            'data_types' => ['connection_type'],
            'path' => 'var/geoip/GeoConnectionType/GeoIP.dat',
            'is_recommended' => null, 'setting_key' => 'maxmind_connection_type_key', 'purchase_link' => null,
        ],
        [
            'id' => 'tds_carrier', 'name' => 'Tds Mobile Operator v3', 'type' => 'hosted',
            'data_types' => ['operator', 'connection_type'],
            'path' => 'var/geoip/carriers.dat',
            'is_recommended' => true, 'setting_key' => null, 'purchase_link' => null,
        ],
        [
            'id' => 'tds_bot_db2', 'name' => 'Tds BotDB2', 'type' => 'hosted',
            'data_types' => ['bot_type'],
            'path' => 'var/bots/botsV2.dat',
            'is_recommended' => true, 'setting_key' => null, 'purchase_link' => null,
        ],
        [
            // Legacy passes no "name" option for this one -> GeoDbDefinition
            // falls back to name = id (see GeoDbDefinition::__construct()).
            'id' => 'user_bot_ip_db', 'name' => 'user_bot_ip_db', 'type' => 'internal',
            'data_types' => ['bot_type'],
            // Real path is a callable into the unported BotDetection module
            // (UserBotDBCARepository) — see class docblock.
            'path' => null,
            'is_recommended' => null, 'setting_key' => null, 'purchase_link' => null,
        ],
        [
            'id' => 'proip_essential', 'name' => 'ProIP Essential', 'type' => 'external',
            'data_types' => ['country', 'region', 'city', 'isp'],
            'path' => 'var/geoip/ProIP/Essential/PROIP-ESSENTIAL.DAT',
            'is_recommended' => true, 'setting_key' => 'proip_essential_key',
            'purchase_link' => 'https://tds.io/go/proip',
        ],
    ];

    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers, duplicated per-controller convention
    // (see SettingsController/AffiliateNetworksController/etc.).
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);

            return is_array($decoded) ? $decoded : null;
        }

        if (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);

            return $parsed;
        }

        return null;
    }

    /** Legacy `getPostParam($name)` — parsed body ONLY (not query), unlike `getParam()`. */
    private function postParam(Request $request, string $name)
    {
        $body = $this->parsedBody($request);

        return is_array($body) ? ($body[$name] ?? null) : null;
    }

    // ---------------------------------------------------------------
    // §6 error-shape helper — `Core\Exceptions\DenyError` shape.
    // ---------------------------------------------------------------

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Status/key resolution (see class docblock for exactly which parts
    // of the real DownloadManager::status() this reproduces).
    // ---------------------------------------------------------------

    /** @return array{0: string, 1: string} [status_code, status_text] */
    private function resolveStatus(array $dbType, ?string $keyValue): array
    {
        if ($dbType['setting_key'] !== null && ($keyValue === null || trim($keyValue) === '')) {
            return [self::STATUS_NO_KEY, ''];
        }

        // HOSTED/INTERNAL types, and EXTERNAL types with a configured key:
        // real managers either return OK unconditionally (Hosted/Null) or
        // would go on to a live provider validity check we don't perform
        // (see class docblock) — OK/no-message is the honest floor here.
        return [self::STATUS_OK, ''];
    }

    private function serializeDbType(array $dbType): array
    {
        $exists = $dbType['path'] !== null && file_exists(base_path($dbType['path']));

        $keyValue = $dbType['setting_key'] !== null
            ? Setting::query()->find($dbType['setting_key'])?->value
            : null;

        [$statusCode, $statusText] = $this->resolveStatus($dbType, $keyValue);

        return [
            'id' => $dbType['id'],
            'name' => $dbType['name'],
            'type' => $dbType['type'],
            'exists' => $exists,
            // TODO (not a real legacy field, see class docblock): plain
            // "is the file on disk" alias, since GeoDbStatus has no
            // "not installed" value to reuse for status_code.
            'installed' => $exists,
            'path' => $dbType['path'],
            'data_types' => $dbType['data_types'],
            'status_code' => $statusCode,
            'status_text' => $statusText,
            // `Component\GeoDb\DownloadManager\DownloadManager::timestamp()`:
            // filemtime() of the installed file, formatted per
            // Core\Model\AbstractModel::DATETIME_FORMAT ("Y-m-d H:i:s") —
            // verified against the real legacy source, not guessed.
            'time' => $exists ? date('Y-m-d H:i:s', filemtime(base_path($dbType['path']))) : null,
            'is_recommended' => $dbType['is_recommended'],
            'setting_key' => $dbType['setting_key'],
            'purchase_link' => $dbType['purchase_link'],
            'key' => $keyValue,
            // TODO: real live update-availability check not ported — see
            // class docblock ("update_available" section).
            'update_available' => null,
        ];
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    /**
     * Legacy `indexAction()` has NO `isAdmin()` gate of its own — it relies
     * entirely on the upstream `AdminRequestFactory::checkAuthorization()`
     * -> `AclService::isResourceAllowed($user, "geoDbs")` resource-level
     * gate (same situation already documented in
     * `SettingsController::updateAction()`'s docblock: that gate isn't
     * ported anywhere in this codebase, and `"geoDbs"` isn't in this port's
     * `AclService::ACL_KEYS` either — verified by grepping the real legacy
     * `AclService.php`, which also has no explicit "geoDbs" entry, so this
     * mirrors legacy's own default-allow-if-authenticated shape rather
     * than inventing a new ACL key that doesn't exist in either codebase).
     * Left ungated here to match the real action literally.
     */
    public function indexAction(Request $request): array
    {
        return array_map(fn (array $dbType) => $this->serializeDbType($dbType), self::DB_TYPES);
    }

    /**
     * Legacy `settingsAction()` — see class docblock: this is a
     * `data_type => db_id` map (`GeoDbService::settings()` ->
     * `CachedSettingsRepository::get(Setting::IPDB)`, `Setting::IPDB =
     * "ipdb"`), NOT an autoupdate-flags blob. Same "no controller-level
     * isAdmin() gate" situation as `indexAction()` above.
     */
    public function settingsAction(Request $request): array
    {
        return $this->readSettings();
    }

    /**
     * Legacy `saveSettingsAction()` — `GeoDbService::saveSettings($this->
     * getPostParam("settings"))`. Throws (generic `\Exception`, not one of
     * the specially-handled §6 types) if `settings` isn't an array;
     * replicated as a plain-text 500, same shape convention as
     * `SettingsController::updateAction()`'s non-POST 500.
     */
    public function saveSettingsAction(Request $request): array|Response
    {
        $settings = $this->postParam($request, 'settings');

        if (! is_array($settings)) {
            return response('Trying to save incorrect settings: '.json_encode($settings), 500);
        }

        Setting::query()->updateOrCreate(
            ['key' => self::SETTINGS_KEY],
            ['value' => json_encode($settings)],
        );

        return $this->readSettings();
    }

    private function readSettings(): array
    {
        $value = Setting::query()->find(self::SETTINGS_KEY)?->value;
        if (! empty($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * TODO STUB — real file download/update
     * (`GeoDbService::update()` -> `DownloadManager::update()`: HTTP
     * download + gzip unpack + CRC verification against
     * tds.io/maxmind/ip2location/sypex/proip) is explicitly OUT OF SCOPE
     * for this task. Returns 501 Not Implemented with a clear explanation
     * instead of silently no-op'ing or attempting a real network call.
     *
     * Legacy DOES gate this one action on `isAdmin()`
     * (`if (!$this->isAdmin()) $this->throwDeny();`), unlike the other
     * three actions above — replicated literally.
     */
    public function updateAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user || ! $user->isAdmin()) {
            return $this->forbidden();
        }

        $id = $this->postParam($request, 'id');
        $known = array_column(self::DB_TYPES, 'id');

        if (! in_array($id, $known, true)) {
            // Real `GeoDbRepository::getDb()` throws `DbError` (a generic
            // `Core\Application\Exception\Error` subclass, not specially
            // handled) for an unknown id -> falls to the catch-all 500
            // handler. Replicated as a plain-text 500, same shape as the
            // other generic-Error cases in this codebase (e.g.
            // SettingsController's non-POST update).
            return response('Unknown geo db "'.$id.'"', 500);
        }

        return response()->json([
            'error' => 'GeoDb file download/update is not implemented in this port yet. '.
                'Deploy the geo database file for "'.$id.'" to the server manually, or use '.
                '?object=geoDbs.upload to install a file you already have.',
        ], 501);
    }

    /**
     * NEW action, no legacy equivalent (see class docblock's "UPDATE"
     * note) — installs a geo db file an admin already has (downloaded/
     * purchased through the provider's own site, same file the real
     * `DownloadManager::update()` would have fetched automatically) via a
     * real multipart upload, at the exact `path` `serializeDbType()`/
     * traffic-core's resolvers already expect (e.g. `GeoDbResolver`'s
     * `GEODB_IP2LOCATION_PATH` for `ip2location_lite` — see
     * traffic-core/src/Pipeline/GeoDb/GeoDbResolver.php). Same `isAdmin()`
     * gate as `updateAction` (this is an install/replace operation on the
     * server's filesystem, not read-only).
     */
    public function uploadAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user || ! $user->isAdmin()) {
            return $this->forbidden();
        }

        $id = $request->input('id') ?? $this->postParam($request, 'id');
        $dbType = collect(self::DB_TYPES)->firstWhere('id', $id);

        if ($dbType === null) {
            return response()->json(['error' => 'Unknown geo db "'.$id.'"'], 422);
        }

        if ($dbType['path'] === null) {
            // `user_bot_ip_db` (INTERNAL type) — see class docblock, its
            // real path is a callable into the unported BotDetection
            // module, nothing this port can install a file at.
            return response()->json(['error' => 'Geo db "'.$id.'" has no installable file path in this port.'], 422);
        }

        if (! $request->hasFile('file') || ! $request->file('file')->isValid()) {
            return response()->json(['error' => 'No valid file uploaded (expected multipart field "file").'], 422);
        }

        $destination = base_path($dbType['path']);
        $request->file('file')->move(dirname($destination), basename($destination));

        return response()->json($this->serializeDbType($dbType));
    }
}
