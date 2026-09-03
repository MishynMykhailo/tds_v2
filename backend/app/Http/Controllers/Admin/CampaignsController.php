<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Stream;
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
 * `Component\Campaigns\Controller\CampaignsController` +
 * `Component\Campaigns\Serializer\CampaignSerializer` (old codebase:
 * application/Component/Campaigns/Controller/CampaignsController.php,
 * application/Component/Campaigns/Serializer/CampaignSerializer.php).
 *
 * Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
 * §6 (response/error format), §7 (param reading), §8 (serialization), §10.1
 * (Campaigns).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, listAsOptions) — see per-method TODOs for what still
 * depends on modules not yet ported (Domains, Groups, TrafficSources,
 * Streams, Postbacks, ACL, Grid/withStats).
 */
class CampaignsController extends Controller
{
    /** Legacy `Traffic\Model\Campaign::$_aclKey` — see AclService docblock. */
    private const ACL_KEY = 'campaigns';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7). Deliberately NOT using
    // Illuminate\Http\Request::input() as-is: Laravel's input() merges
    // parsed-body + query with *body* taking priority on key collision
    // (getInputSource()->all() + query->all(), array `+` favors the left
    // side). The old `BaseController::getParam()` has the OPPOSITE
    // priority — query wins over body. We replicate the old behavior
    // exactly, including old's "body type sniffed from first byte,
    // Content-Type ignored" parsing rule.
    // ---------------------------------------------------------------

    /**
     * Mirrors `Traffic\Request\ServerRequestFactory::parseBody()`: body type
     * is guessed from the first non-whitespace byte, NOT from the
     * Content-Type header. `{`/`[` => JSON, contains `&` => querystring,
     * otherwise => NULL (no body).
     */
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

    /** Legacy `getPostParam($name)` — parsed body only, no query fallback. */
    private function postParam(Request $request, string $name, $default = null)
    {
        $body = $this->parsedBody($request);

        return is_array($body) && array_key_exists($name, $body) ? $body[$name] : $default;
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
        return response()->json(['error' => $message, 'stacktrace' => ''], 404);
    }

    /** `Core\Validator\ValidationError` shape: 406, {field: ["message", ...]}. */
    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    /** `ADODB_Exception` (DB error) shape: 500, {"error", "stacktrace"}. */
    private function dbError(QueryException $e): Response
    {
        return response()->json(['error' => $e->getMessage(), 'stacktrace' => ''], 500);
    }

    /** `Core\Exceptions\DenyError` shape (§5/§6): 403, {"error": "..."}. */
    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Serialization (§8 + CampaignSerializer::extra())
    // ---------------------------------------------------------------

    /**
     * @param  bool  $extended  adds group/streams_count/ts/postbacks
     * @param  bool  $withStreams  adds nested `streams` (show only)
     */
    private function serializeCampaign(Campaign $campaign, bool $extended = false, bool $withStreams = false): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()` — the
        // "$_fields = true" pass-through), not any in-memory PHP cast
        // (e.g. `parameters` array cast) picked up from a just-built model.
        $campaign->refresh();

        $data = $campaign->getAttributes();

        // cost_type CPV -> CPC legacy display substitution.
        if (($data['cost_type'] ?? null) === 'CPV') {
            $data['cost_type'] = 'CPC';
        }

        // domain resolution (DomainService::urlWithBasePath in old code).
        // TODO: домены ещё не перенесены — Domains module not ported yet.
        $data['domain'] = null;
        if (empty($data['domain_id'])) {
            $data['domain_id'] = null;
        }

        if ($extended) {
            if (empty($data['group_id'])) {
                $data['group'] = 'Default';
                $data['group_id'] = 0;
            } else {
                // TODO: Groups module not ported yet — real group name
                // resolution (GroupsRepository::getName()) is pending.
                $data['group'] = 'Default';
            }

            // TODO: Streams module not ported yet — real count from
            // StreamRepository::getCampaignStreamsCount().
            $data['streams_count'] = 0;

            if (! empty($data['traffic_source_id'])) {
                // TODO: TrafficSources module not ported yet — real name
                // resolution (TrafficSourceRepository::getName()).
                $data['ts'] = null;
            } else {
                $data['traffic_source_id'] = null;
                $data['ts'] = null;
            }

            // TODO: Postback module not ported yet — real list via
            // CampaignPostbackRepository::getCampaignPostbacks().
            $data['postbacks'] = [];
        }

        if ($withStreams) {
            // TODO: Streams module not ported yet — real nested list via
            // StreamRepository::allOrderedStreamsForCampaign() +
            // StreamSerializer(true, true).
            $data['streams'] = [];
        }

        // cost_value -> int for RevShare cost model (Campaign::isCostRevShare(),
        // COST_TYPE_REV_SHARE === "RevShare" in the old codebase).
        if (($data['cost_type'] ?? null) === 'RevShare') {
            $data['cost_value'] = (int) $data['cost_value'];
        }

        if (isset($data['cookies_ttl'])) {
            $data['cookies_ttl'] = (int) $data['cookies_ttl'];
        }

        if (isset($data['traffic_loss'])) {
            $data['traffic_loss'] = (float) $data['traffic_loss'];
        }

        unset($data['mode']);

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
        $active = $this->boolParam($this->param($request, 'active'));

        $query = Campaign::query();
        if ($active) {
            $query->where('state', 'active');
        } else {
            // "allNotDeleted" in the old code, replicated here as
            // state != 'deleted' (deleted campaigns are soft-state, not
            // physically removed, same as legacy).
            $query->where('state', '!=', 'deleted');
        }

        $extended = $this->boolParam($this->param($request, 'extended'));

        $campaigns = $query->orderBy('id')->get();

        $campaigns = $this->aclService->filterByAcl($campaigns, false, $this->currentUserService->get());

        return array_values(array_map(fn (Campaign $c) => $this->serializeCampaign($c, $extended), $campaigns));
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Campaign not found');
        }

        $campaign = Campaign::find((int) $id);

        if (! $campaign) {
            return $this->notFound('Campaign not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign');
        }

        $withStreams = $this->boolParam($this->param($request, 'withStreams'));
        if ($withStreams && ! $this->aclService->isResourceAllowed($this->currentUserService->get(), 'streams')) {
            $withStreams = false;
        }

        return response()->json($this->serializeCampaign($campaign, true, $withStreams));
    }

    public function createAction(Request $request): Response
    {
        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), self::ACL_KEY)) {
            return $this->forbidden('You are not allowed to create campaigns');
        }

        // TODO: trial-mode campaign limit checks not ported yet.

        $params = $this->postParams($request);
        $errors = $this->validateCampaignParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $campaign = new Campaign;
        $campaign->fill($this->fillableParams($params));

        try {
            $campaign->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        if (array_key_exists('streams', $params) && is_array($params['streams'])) {
            $this->saveNestedStreams($campaign, $params['streams']);
        }

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addAuthorPermission($this->currentUserService->get(), $campaign);

        return response()->json($this->serializeCampaign($campaign, true, true));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Campaign not found');
        }

        $campaign = Campaign::find((int) $id);

        if (! $campaign) {
            return $this->notFound('Campaign not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to edit this campaign');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateCampaignParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $campaign->fill($this->fillableParams($params));

        try {
            $campaign->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        if (array_key_exists('streams', $params) && is_array($params['streams'])) {
            $this->saveNestedStreams($campaign, $params['streams']);
        }

        return response()->json($this->serializeCampaign($campaign, true, true));
    }

    /**
     * Nested `streams: [...]` create/update, called from createAction() and
     * updateAction() when the campaign payload carries an inline streams
     * list. Delegates the actual row-building to
     * `StreamsController::createStreamRecord()`/`updateStreamRecord()`
     * (invoked programmatically, not via HTTP) so the validation/action_type
     * do_nothing/action_options-JSON-encode logic lives in exactly one
     * place.
     *
     * This is a SIMPLIFIED port of legacy `StreamService::updateStreams()`:
     * each element with an `id` is updated, each element without one is
     * created. What's NOT replicated (TODO):
     * - legacy archives any of the campaign's existing streams that are
     *   NOT present in the incoming list (full-replace semantics) — here,
     *   streams simply omitted from the payload are left untouched;
     * - legacy wraps the whole operation in a single DB transaction
     *   (`Db::instance()->beginTransaction()`/`commit()`) — here each
     *   stream is saved independently, so a failure partway through does
     *   NOT roll back streams already saved;
     * - `_patchWeight()` (auto-fills `weight` from `position` for
     *   TYPE_WEIGHT campaigns) and `CampaignService::resortStreams()` are
     *   not ported.
     * A validation error or DB error on one stream is swallowed (skipped)
     * rather than failing the whole campaign save, since the campaign row
     * is already committed by the time this runs.
     */
    private function saveNestedStreams(Campaign $campaign, array $streams): void
    {
        $streamsController = app(StreamsController::class);

        foreach ($streams as $streamData) {
            if (! is_array($streamData)) {
                continue;
            }

            try {
                if (! empty($streamData['id'])) {
                    $stream = Stream::where('id', (int) $streamData['id'])
                        ->where('campaign_id', $campaign->id)
                        ->first();

                    if ($stream) {
                        $streamsController->updateStreamRecord($stream, $streamData);

                        continue;
                    }
                }

                $streamsController->createStreamRecord($streamData, $campaign->id);
            } catch (QueryException $e) {
                // TODO: surface per-stream errors to the caller instead of
                // silently skipping (legacy's single transaction would have
                // rolled back the whole batch on any DB error).
                continue;
            }
        }
    }

    public function listAsOptionsAction(Request $request): array
    {
        $includeDisabledParam = $this->param($request, 'include_disabled');
        $includeDisabled = $includeDisabledParam !== null
            ? $this->boolParam($includeDisabledParam)
            : true;
        $addBlank = $this->boolParam($this->param($request, 'add_blank'));
        $key = $this->param($request, 'key') ?: 'id';

        $query = Campaign::query();
        if (! $includeDisabled) {
            $query->where('state', 'active');
        } else {
            $query->where('state', '!=', 'deleted');
        }

        $campaigns = $query->orderBy('position')->orderBy('id')->get();
        $campaigns = $this->aclService->filterByAcl($campaigns, false, $this->currentUserService->get());

        $items = [];
        if ($addBlank) {
            $items[] = ['id' => '', 'name' => 'Choose campaign'];
        }

        foreach ($campaigns as $campaign) {
            // TODO: Groups module not ported yet — real group name via
            // GroupsRepository::getName(); hardcoded "Default" for now.
            $items[] = [
                'id' => $campaign->id,
                'name' => $campaign->name,
                'group_id' => $campaign->group_id,
                'group' => 'Default',
                'value' => (int) ($key === 'id' ? $campaign->id : $campaign->{$key}),
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Grid / withStats (§9). Real source read (not just the doc):
    // application/Component/EntityGrid/EntityGridFactory.php,
    // application/Component/Reports/Grid/ReportDefinition.php,
    // application/Component/Clicks/Grid/ClicksDefinition.php,
    // application/Component/Campaigns/Grid/CampaignGridDefinition.php.
    // ---------------------------------------------------------------

    /**
     * Legacy `campaigns.withStats` -> `CampaignRepository::allWithStats()`
     * -> `Component\EntityGrid\EntityGridFactory::build()`. See
     * App\Services\Grid\EntityGridBuilder for the port and its docblocks
     * for every deviation from the real legacy source found while reading
     * it (metric SQL, meta.total shape, pagination semantics).
     *
     * ACL is enforced inside EntityGridBuilder::applyAcl() (called from
     * loadEntities()), fed by the `user:` param passed below — see
     * tests/Feature/GridAclTest.php for live coverage.
     */
    public function withStatsAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new EntityGridBuilder(
            entityClass: Campaign::class,
            statsIdColumn: 'campaign_id',
            entityFields: ['id', 'name', 'state', 'group_id'],
            user: $this->currentUserService->get(),
        );

        return $builder->build($params);
    }

    /**
     * Legacy `campaigns.gridDefinition` -> `CampaignGridDefinition::
     * getGridDefinition()`. Minimal column set per the task brief: the
     * entity's own id/name/state fields (CampaignGridDefinition's
     * "virtual" id/name/state columns, application/Component/Campaigns/
     * Grid/CampaignGridDefinition.php:20-23) plus the base metrics
     * EntityGridBuilder computes (clicks/conversions/revenue/cost/profit,
     * inherited from ReportDefinition::initColumns()). `checkbox`/`ts`/
     * `streams_count`/`more` and the full report metric catalogue
     * (uc_*_rate, roi, epc, ...) are legacy UI-only or out of scope for
     * this round.
     *
     * `range_intervals: null` (not `[]`) is intentional: the real
     * `ReportDefinition` (which `CampaignGridDefinition` extends)
     * redeclares `protected $_rangeIntervals = NULL;`, overriding the
     * `GridDefinition` base class's `[]` default — verified by reading
     * both classes, not assumed from the doc's generic `[...]` example.
     */
    public function gridDefinitionAction(Request $request): array
    {
        return [
            'url' => '?object=campaigns.withStats',
            'details' => null,
            'range_intervals' => null,
            'columns' => [
                ['name' => 'id', 'type' => 'integer', 'title' => 'grid.id', 'th_title' => 'grid.id', 'sortable' => true, 'category' => 'data', 'width' => 41],
                ['name' => 'name', 'type' => 'string', 'title' => 'grid.name', 'th_title' => 'grid.name', 'sortable' => true, 'category' => 'data', 'width' => 200],
                ['name' => 'state', 'type' => 'string', 'title' => 'grid.state', 'th_title' => 'grid.state', 'sortable' => true, 'category' => 'data', 'width' => 20],
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
     * Minimal port of the legacy campaign validator: `name` and `alias` are
     * required. Old codebase's full validator (`Component\Campaigns\Service\
     * CampaignService` / `Traffic\Model\Campaign` validation rules) covers
     * more fields (uniqueness of alias, enum checks on type/state/cost_type,
     * etc.) — TODO: port the remaining rules once the corresponding
     * reference/enums modules exist.
     */
    private function validateCampaignParams(array $params, bool $partial = false): array
    {
        $errors = [];

        foreach (['name', 'alias'] as $field) {
            $present = array_key_exists($field, $params);
            $empty = $present && trim((string) $params[$field]) === '';

            if ((! $partial && (! $present || $empty)) || ($partial && $present && $empty)) {
                $errors[$field] = ["The {$field} field is required."];
            }
        }

        return $errors;
    }

    private function fillableParams(array $params): array
    {
        return array_intersect_key($params, array_flip((new Campaign)->getFillable()));
    }
}
