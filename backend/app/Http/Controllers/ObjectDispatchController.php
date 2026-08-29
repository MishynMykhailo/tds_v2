<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\AdminApiController;
use App\Http\Controllers\Admin\AffiliateNetworksController;
use App\Http\Controllers\Admin\ApiKeysController;
use App\Http\Controllers\Admin\AppsFlyerIntegrationController;
use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\BotlistController;
use App\Http\Controllers\Admin\BrandingController;
use App\Http\Controllers\Admin\CampaignsController;
use App\Http\Controllers\Admin\CleanerController;
use App\Http\Controllers\Admin\CodePresetsController;
use App\Http\Controllers\Admin\ConversionsController;
use App\Http\Controllers\Admin\DicsController;
use App\Http\Controllers\Admin\DomainsController;
use App\Http\Controllers\Admin\EditorController;
use App\Http\Controllers\Admin\FacebookIntegrationController;
use App\Http\Controllers\Admin\FavouriteReportController;
use App\Http\Controllers\Admin\FavouriteStreamsController;
use App\Http\Controllers\Admin\GeoDbsController;
use App\Http\Controllers\Admin\GeoProfilesController;
use App\Http\Controllers\Admin\GroupsController;
use App\Http\Controllers\Admin\KClientJsPresetController;
use App\Http\Controllers\Admin\LabelsController;
use App\Http\Controllers\Admin\LandingsController;
use App\Http\Controllers\Admin\OffersController;
use App\Http\Controllers\Admin\PostbackTemplatesController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\Admin\ReportsController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\StatusController;
use App\Http\Controllers\Admin\StreamActionsController;
use App\Http\Controllers\Admin\StreamEventsController;
use App\Http\Controllers\Admin\StreamFiltersController;
use App\Http\Controllers\Admin\StreamSchemasController;
use App\Http\Controllers\Admin\StreamsController;
use App\Http\Controllers\Admin\StreamTypesController;
use App\Http\Controllers\Admin\TrafficSourcesController;
use App\Http\Controllers\Admin\IpInfoDataTypesController;
use App\Http\Controllers\Admin\ResourceController;
use App\Http\Controllers\Admin\TrafficSourceTemplatesController;
use App\Http\Controllers\Admin\TriggersController;
use App\Http\Controllers\Admin\MacrosController;
use App\Http\Controllers\Admin\TrendsController;
use App\Http\Controllers\Admin\DiagnosticsController;
use App\Http\Controllers\Admin\ThirdPartyIntegrationController;
use App\Http\Controllers\Admin\TpiMandatoryController;
use App\Http\Controllers\Admin\UserPreferencesController;
use App\Http\Controllers\Admin\UsersController;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility layer replicating the legacy `?object=controller.action`
 * routing contract (see docs/legacy-reference/frontend/backend_api_reference.md
 * §2). This is a deliberate design decision to keep the external API
 * contract identical to the old backend, NOT a Laravel default behavior —
 * see docs/ARCHITECTURE_PLAN.md.
 *
 * Legacy behavior replicated:
 * - `object=campaigns.show` -> controller "campaigns", action "show"
 * - no dot -> action defaults to "index"
 * - method resolved is "<action>Action" on the registered controller
 * - array/object return values are JSON-encoded; anything else is returned
 *   as-is (matches ResponseFactory::safeBody() in the old codebase)
 */
class ObjectDispatchController extends Controller
{
    /**
     * @var array<string, class-string> Mirrors Initializer.php `register()`
     *                                  calls in the old codebase — add one entry per ported module.
     */
    private const CONTROLLERS = [
        'campaigns' => CampaignsController::class,
        'cleaner' => CleanerController::class,
        'conversions' => ConversionsController::class,
        'reports' => ReportsController::class,
        // Legacy `Component\AffiliateNetworks\Initializer::loadControllers()`
        // registers this controller under the exact key "affiliateNetworks"
        // (confirmed by reading that Initializer directly, and independently
        // by tests-contract/tests/AffiliateNetworksTest.php's docblock:
        // "Confirmed live: ?object=affiliateNetworks.index returns 200, not
        // 404"). Lowercased here to "affiliatenetworks" per this array's
        // established convention (matches handle()'s strtolower() lookup and
        // every other multi-word key below: trafficsources, streamfilters,
        // streamactions, ...). This is NOT the same string as the
        // "affiliate_networks" ACL key (see AclService::ACL_KEYS) — those are
        // two independently-confirmed, differently-cased identifiers for two
        // different purposes. Both are registered here (the second as a
        // no-cost alias) since the task brief asserted "affiliate_networks"
        // as the object key without that distinction.
        'affiliatenetworks' => AffiliateNetworksController::class,
        'affiliate_networks' => AffiliateNetworksController::class,
        'streams' => StreamsController::class,
        'auth' => AuthController::class,
        'offers' => OffersController::class,
        'landings' => LandingsController::class,
        'trafficsources' => TrafficSourcesController::class,
        'domains' => DomainsController::class,
        // Legacy `Component\Editor\Initializer::loadControllers()` registers
        // this as `$repo->register("editor", ...)` (confirmed by reading
        // that Initializer directly) — already lowercase.
        'editor' => EditorController::class,
        'streamfilters' => StreamFiltersController::class,
        'triggers' => TriggersController::class,
        'streamactions' => StreamActionsController::class,
        'streamtypes' => StreamTypesController::class,
        'streamschemas' => StreamSchemasController::class,
        'favouritestreams' => FavouriteStreamsController::class,
        'streamevents' => StreamEventsController::class,
        'users' => UsersController::class,
        'groups' => GroupsController::class,
        'profile' => ProfileController::class,
        'apikeys' => ApiKeysController::class,
        'userpreferences' => UserPreferencesController::class,
        // Legacy `Component\Settings\Initializer::loadControllers()` (there
        // is no separate `Component\Dics` module) registers both under
        // these exact lowercase, single-word keys —
        // `$repo->register("settings", ...)` / `$repo->register("dics",
        // ...)` — confirmed by reading that Initializer directly. Both are
        // already all-lowercase single words, so unlike "affiliateNetworks"
        // above there's no camelCase-vs-lowercase ambiguity to hedge with a
        // second alias.
        'settings' => SettingsController::class,
        'dics' => DicsController::class,
        // Legacy `Component\AdminApi\Initializer::loadControllers()`:
        // `$repo->register("adminApi", ...)` — camelCase in source,
        // lowercased here per this array's convention.
        'adminapi' => AdminApiController::class,
        'resource' => ResourceController::class,
        'trafficsourcetemplates' => TrafficSourceTemplatesController::class,
        'ipinfodatatypes' => IpInfoDataTypesController::class,
        // Legacy `object=geoDbs` (confirmed by
        // docs/legacy-reference/frontend/api/10.9_geodb.md's literal
        // `object=geoDbs` heading) -> lowercased per this array's
        // convention.
        'geodbs' => GeoDbsController::class,
        // Legacy `LabelsController`/`FavouriteReportController` physically
        // live inside `application/Component/Reports/Controller/` but are
        // registered as their own `?object=` keys, NOT `reports.*` actions
        // (confirmed by reading both classes directly) — "labels" and
        // "favouriteReports" respectively, lowercased here per this array's
        // convention.
        'labels' => LabelsController::class,
        'favouritereports' => FavouriteReportController::class,
        // Legacy `Component\BotDetection\Initializer::loadControllers()`
        // registers this as `$repo->register("botlist", ...)` (confirmed by
        // reading that Initializer directly) — already lowercase.
        'botlist' => BotlistController::class,
        'branding' => BrandingController::class,
        'macros' => MacrosController::class,
        'trends' => TrendsController::class,
        'diagnostics' => DiagnosticsController::class,
        // `Component\Postback\Controller\PostbackTemplatesController` — see
        // that controller's own docblock for why it's permanently empty.
        'postbacktemplates' => PostbackTemplatesController::class,
        'status' => StatusController::class,
        // Legacy `ThirdPartyIntegration\Initializer::loadControllers()`
        // registers these exact keys — confirmed by reading that
        // Initializer directly.
        'thirdpartyintegration' => ThirdPartyIntegrationController::class,
        'tpimandatory' => TpiMandatoryController::class,
        'facebookintegration' => FacebookIntegrationController::class,
        'appsflyerintegration' => AppsFlyerIntegrationController::class,
        // Legacy `CampaignIntegration\Initializer::loadControllers()`
        // registers `"codePresets"`/`"kClientJSPreset"` (camelCase in
        // source) — lowercased here per this array's convention.
        'codepresets' => CodePresetsController::class,
        'kclientjspreset' => KClientJsPresetController::class,
        // Legacy `GeoProfiles\Initializer::loadControllers()` registers
        // `"geoProfiles"` (camelCase) — lowercased per convention.
        'geoprofiles' => GeoProfilesController::class,
    ];

    public function handle(Request $request): Response
    {
        $object = $request->input('object', 'home.index');
        [$controllerName, $action] = str_contains($object, '.')
            ? explode('.', $object, 2)
            : [$object, 'index'];

        $controllerName = strtolower($controllerName);

        if (! isset(self::CONTROLLERS[$controllerName])) {
            return response('Not Found', 404);
        }

        $controllerClass = self::CONTROLLERS[$controllerName];
        $method = $action.'Action';

        if (! method_exists($controllerClass, $method)) {
            return response('Not Found', 404);
        }

        $controller = app($controllerClass);
        $result = $controller->{$method}($request);

        // An action may need a specific HTTP status (404/406/etc, see §6) —
        // in that case it builds its own Response/JsonResponse and we must
        // pass it through untouched, not re-wrap it as JSON payload data.
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result) || is_object($result)) {
            return response()->json($result);
        }

        return response((string) $result);
    }
}
