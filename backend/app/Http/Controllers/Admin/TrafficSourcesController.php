<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TrafficSource;
use App\Services\AclService;
use App\Services\CurrentUserService;
use App\Services\Grid\EntityGridBuilder;
use App\Services\Grid\QueryParams;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\TrafficSources\Controller\TrafficSourcesController` +
 * `Component\TrafficSources\Serializer\TrafficSourceSerializer` +
 * `Component\TrafficSources\Service\TrafficSourceService` (old codebase:
 * application/Component/TrafficSources/Controller/TrafficSourcesController.php,
 * application/Component/TrafficSources/Serializer/TrafficSourceSerializer.php,
 * application/Component/TrafficSources/Service/TrafficSourceService.php,
 * application/Component/TrafficSources/Validator/TrafficSourceValidator.php,
 * application/Component/TrafficSources/Repository/TrafficSourceRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.6_trafficsources.md,
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §5-8 (ACL/errors/params/serialization).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, listAsOptions, withStats, gridDefinition) — see
 * per-method TODOs for what still depends on modules not yet ported
 * (postbackStatuses/availableParameters/parameterAliases/archive/clone/
 * restore/deleted/cleanArchive/saveNote).
 *
 * NOTE on legacy `indexAction`: the old controller calls
 * `TrafficSourceRepository::allActive()` (state == active only), NOT
 * `allNotDeletedWithRelations()` like Offers/Landings — replicated exactly
 * below (differs from OffersController/LandingsController::indexAction on
 * purpose).
 */
class TrafficSourcesController extends Controller
{
    /** Legacy `Traffic\Model\TrafficSource::$_aclKey` — see AclService docblock. */
    private const ACL_KEY = 'traffic_sources';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated from
    // CampaignsController/StreamsController/OffersController/
    // LandingsController rather than shared via inheritance, per the task
    // instructions (kept independent so as not to risk breaking the
    // already-implemented controllers).
    // ---------------------------------------------------------------

    private function parsedBody(Request $request): ?array
    {
        static $cache = null;
        static $cachedFor = null;

        if ($cachedFor === $request) {
            return $cache;
        }

        $raw = $request->getContent();
        $trimmed = is_string($raw) ? ltrim($raw) : '';

        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true);
            $result = is_array($decoded) ? $decoded : null;
        } elseif (is_string($raw) && str_contains($raw, '&')) {
            parse_str($raw, $parsed);
            $result = $parsed;
        } else {
            $result = null;
        }

        $cachedFor = $request;
        $cache = $result;

        return $result;
    }

    /** Legacy `getParam($name)` — query first, then parsed body. */
    private function param(Request $request, string $name, $default = null)
    {
        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        $body = $this->parsedBody($request);
        if (is_array($body) && array_key_exists($name, $body)) {
            return $body[$name];
        }

        return $default;
    }

    /** Legacy `getPostParams()` — the whole parsed body. */
    private function postParams(Request $request): array
    {
        return $this->parsedBody($request) ?? [];
    }

    /** Legacy `isPost()` — non-empty parsed body OR HTTP method POST. */
    private function isPost(Request $request): bool
    {
        return ! empty($this->parsedBody($request)) || $request->isMethod('post');
    }

    private function boolParam($value, bool $default = false): bool
    {
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    // ---------------------------------------------------------------
    // §6 error-shape helpers
    // ---------------------------------------------------------------

    /** `Core\Exceptions\NotFoundError` shape: 404, {"error", "stacktrace"}. */
    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    /** `Core\Validator\ValidationError` shape: 406, {field: ["message", ...]}. */
    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    /** `ADODB_Exception` (DB error) shape: 500, {"error", "stacktrace"}. */
    private function dbError(QueryException $e): Response
    {
        return response()->json(['error' => $e->getMessage(), 'stacktrace' => $e->getTraceAsString()], 500);
    }

    /** `Core\Exceptions\DenyError` shape (§5/§6): 403, {"error": "..."}. */
    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // `parameters`/`postback_statuses` JSON-string <-> array (§8 "JSON
    // внутри поля модели"). Both `traffic_sources.parameters` and
    // `traffic_sources.postback_statuses` are TEXT/VARCHAR columns holding
    // a JSON string (e.g. `["sale","lead","rejected","rebill"]` for
    // postback_statuses) — decoded/encoded here rather than via
    // TrafficSource::$casts, same pattern as
    // OffersController/LandingsController/StreamsController's
    // action_options handling.
    // ---------------------------------------------------------------

    private function decodeJsonField($raw)
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    private function encodeJsonFieldForWrite($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    // ---------------------------------------------------------------
    // Serialization (§8). TrafficSourceSerializer is trivial in the old
    // codebase ($_fields = true, no extra()) — just decode the two JSON
    // string fields.
    // ---------------------------------------------------------------

    private function serializeTrafficSource(TrafficSource $source): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()`), same
        // pattern as the other ported controllers.
        $source->refresh();

        $data = $source->getAttributes();

        if (array_key_exists('parameters', $data)) {
            $data['parameters'] = $this->decodeJsonField($data['parameters']);
        }

        if (array_key_exists('postback_statuses', $data)) {
            $data['postback_statuses'] = $this->decodeJsonField($data['postback_statuses']);
        }

        // getAttributes() bypasses TrafficSource::$casts (boolean casts
        // only apply through attribute access) — cast the raw DB int (0/1)
        // here to keep the API contract boolean.
        if (array_key_exists('accept_parameters', $data)) {
            $data['accept_parameters'] = (bool) $data['accept_parameters'];
        }

        foreach (['created_at', 'updated_at'] as $key) {
            if (isset($data[$key]) && $data[$key] instanceof \DateTimeInterface) {
                $data[$key] = Carbon::instance($data[$key])->toDateTimeString();
            }
        }

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): array
    {
        // Legacy `allActive()`: state == active, NOT "!= deleted" — see
        // class docblock.
        $sources = TrafficSource::query()
            ->where('state', 'active')
            ->orderBy('id')
            ->get();

        $sources = $this->aclService->filterByAcl($sources, false, $this->currentUserService->get());

        return array_values(array_map(fn (TrafficSource $s) => $this->serializeTrafficSource($s), $sources));
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Traffic source not found');
        }

        $source = TrafficSource::find((int) $id);

        if (! $source) {
            return $this->notFound('Traffic source not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $source)) {
            return $this->forbidden('You are not allowed to view this traffic source');
        }

        return response()->json($this->serializeTrafficSource($source));
    }

    public function createAction(Request $request): Response
    {
        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), self::ACL_KEY)) {
            return $this->forbidden('You are not allowed to create traffic sources');
        }

        $params = $this->postParams($request);
        $errors = $this->validateTrafficSourceParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        // `traffic_sources.state` has no DB-level default (unlike
        // campaigns/streams) — found live via tests-contract/: a freshly
        // created traffic source with no explicit `state` param silently
        // got `state = NULL`, invisible to every listing query
        // afterward. Legacy always creates as 'active'.
        $fill['state'] ??= 'active';
        if (array_key_exists('parameters', $fill)) {
            $fill['parameters'] = $this->encodeJsonFieldForWrite($fill['parameters']);
        }
        if (array_key_exists('postback_statuses', $fill)) {
            $fill['postback_statuses'] = $this->encodeJsonFieldForWrite($fill['postback_statuses']);
        }

        $source = new TrafficSource();
        $source->fill($fill);

        try {
            $source->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addAuthorPermission($this->currentUserService->get(), $source);

        return response()->json($this->serializeTrafficSource($source));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Traffic source not found');
        }

        $source = TrafficSource::find((int) $id);

        if (! $source) {
            return $this->notFound('Traffic source not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $source)) {
            return $this->forbidden('You are not allowed to edit this traffic source');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateTrafficSourceParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        if (array_key_exists('parameters', $fill)) {
            $fill['parameters'] = $this->encodeJsonFieldForWrite($fill['parameters']);
        }
        if (array_key_exists('postback_statuses', $fill)) {
            $fill['postback_statuses'] = $this->encodeJsonFieldForWrite($fill['postback_statuses']);
        }

        $source->fill($fill);

        try {
            $source->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // TODO: legacy `updateTrafficSource()` also supports an
        // `update_in_campaigns` flag that propagates `traffic_loss`/
        // `parameters` to every campaign bound to this source
        // (CampaignService::updateMany) — not ported, Campaigns<->
        // TrafficSource cross-write not in scope for this task.

        return response()->json($this->serializeTrafficSource($source));
    }

    public function listAsOptionsAction(Request $request): array
    {
        // allActive(): state == active.
        $sources = TrafficSource::query()->where('state', 'active')->orderBy('id')->get();
        $sources = $this->aclService->filterByAcl($sources, false, $this->currentUserService->get());

        // Mirrors `Core\Entity\ListOptions\Builder::build()`. "traffic_sources"
        // is NOT a GROUP_ENTITY_TYPE (see AclService::GROUP_ENTITY_TYPES —
        // only campaigns/offers/landings), so no group_id/group keys here,
        // unlike Offers/Landings/Campaigns listAsOptionsAction ports.
        $items = [];
        foreach ($sources as $source) {
            $items[] = [
                'id' => $source->id,
                'value' => $source->id,
                'name' => $source->name,
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Grid / withStats (§9). Real source read (not just the doc):
    // application/Component/EntityGrid/EntityGridFactory.php,
    // application/Component/Reports/Grid/ReportDefinition.php,
    // application/Component/Clicks/Grid/ClicksDefinition.php,
    // application/Component/TrafficSources/Grid/TrafficSourceGridDefinition.php.
    // ---------------------------------------------------------------

    /**
     * Legacy `trafficSources.withStats` -> `TrafficSourceRepository::
     * allWithStats()` -> `Component\EntityGrid\EntityGridFactory::build()`,
     * same shape as `campaigns.withStats`, just grouped by `ts_id` — the
     * `clicks` table column is literally named `ts_id`, NOT
     * `traffic_source_id` (confirmed by reading App\Models\Click::
     * $fillable). See App\Services\Grid\EntityGridBuilder for the port and
     * its docblocks for every deviation from the real legacy source (metric
     * SQL, meta.total shape, pagination semantics).
     *
     * ACL is enforced inside EntityGridBuilder::applyAcl() (called from
     * loadEntities()), fed by the `user:` param passed below — see
     * tests/Feature/GridAclTest.php for live coverage.
     */
    public function withStatsAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new EntityGridBuilder(
            entityClass: TrafficSource::class,
            statsIdColumn: 'ts_id',
            entityFields: ['id', 'name', 'state'],
            user: $this->currentUserService->get(),
        );

        return $builder->build($params);
    }

    /**
     * Legacy `trafficSources.gridDefinition` -> `TrafficSourceGridDefinition
     * ::getGridDefinition()`. Minimal column set per the task brief (same
     * scope decision as CampaignsController::gridDefinitionAction()): the
     * entity's own virtual `id`/`name` columns
     * (TrafficSourceGridDefinition.php — both declared with `"virtual" =>
     * true`; note the real TrafficSourceGridDefinition does NOT add a
     * virtual `state` column at all, unlike CampaignGridDefinition —
     * confirmed by reading the class, not assumed) plus the base metrics
     * EntityGridBuilder computes (clicks/conversions/revenue/cost/profit,
     * inherited unchanged from ReportDefinition::initColumns() — identical
     * inner_select SQL, so identical widths/fraction_size to
     * CampaignsController's columns). `checkbox`/`notes` are legacy UI-only
     * template cells, out of scope for this round — same exclusion
     * rationale as CampaignsController leaving out `checkbox`/`ts`/
     * `streams_count`/`more`.
     *
     * `range_intervals: null`: TrafficSourceGridDefinition extends
     * ReportDefinition, which redeclares `protected $_rangeIntervals =
     * NULL;` — inherited unchanged, same as CampaignGridDefinition.
     */
    public function gridDefinitionAction(Request $request): array
    {
        return [
            'url' => '?object=trafficSources.withStats',
            'details' => null,
            'range_intervals' => null,
            'columns' => [
                ['name' => 'id', 'type' => 'integer', 'title' => 'grid.id', 'th_title' => 'grid.id', 'sortable' => true, 'category' => 'data', 'width' => 50],
                ['name' => 'name', 'type' => 'string', 'title' => 'grid.name', 'th_title' => 'grid.name', 'sortable' => true, 'category' => 'data', 'width' => 200],
                ['name' => 'clicks', 'type' => 'integer', 'title' => 'grid.clicks', 'th_title' => 'grid.clicks', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 52],
                ['name' => 'conversions', 'type' => 'integer', 'title' => 'grid.conversions', 'th_title' => 'grid.conversions_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 50],
                ['name' => 'revenue', 'type' => 'decimal', 'title' => 'grid.revenue', 'th_title' => 'grid.revenue', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 80],
                ['name' => 'cost', 'type' => 'decimal', 'title' => 'grid.cost', 'th_title' => 'grid.cost', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 2, 'width' => 70],
                ['name' => 'profit', 'type' => 'decimal', 'title' => 'grid.profit', 'th_title' => 'grid.profit_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 2, 'width' => 70],
            ],
        ];
    }

    // ---------------------------------------------------------------
    // Validation (§6: ValidationError -> 406 {field: ["message"]})
    // ---------------------------------------------------------------

    /**
     * Minimal port of `TrafficSourceValidator`: only `required`/
     * `lengthMax(50)` on `name` are replicated (same scope decision as
     * OffersController::validateOfferParams() /
     * LandingsController::validateLandingParams() — note the max length
     * here is 50, NOT 100, per the real legacy validator). NOT ported
     * (TODO): uniqueness(name) — no reference module for this ported yet.
     */
    private function validateTrafficSourceParams(array $params, bool $partial = false): array
    {
        $errors = [];

        $present = array_key_exists('name', $params);
        $empty = $present && trim((string) $params['name']) === '';

        if ((! $partial && (! $present || $empty)) || ($partial && $present && $empty)) {
            $errors['name'] = ['The name field is required.'];
        } elseif ($present && ! $empty && mb_strlen((string) $params['name']) > 50) {
            $errors['name'] = ['The name field must not be greater than 50 characters.'];
        }

        return $errors;
    }

    private function fillableParams(array $params): array
    {
        return array_intersect_key($params, array_flip((new TrafficSource())->getFillable()));
    }
}
