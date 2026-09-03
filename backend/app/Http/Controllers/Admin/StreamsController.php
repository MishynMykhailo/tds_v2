<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Stream;
use App\Models\StreamFilter;
use App\Models\StreamLandingAssociation;
use App\Models\StreamOfferAssociation;
use App\Models\Trigger;
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
 * `Component\Streams\Controller\StreamsController` +
 * `Component\Streams\Serializer\StreamSerializer` +
 * `Component\Streams\Service\StreamService` (old codebase:
 * application/Component/Streams/Controller/StreamsController.php,
 * application/Component/Streams/Serializer/StreamSerializer.php,
 * application/Component/Streams/Service/StreamService.php,
 * application/Component/Streams/Repository/StreamRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
 * §6 (response/error format), §7 (param reading), §8 (serialization), §10.2
 * (Streams).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, delete/archive, listAsOptions) — see per-method TODOs for
 * what still depends on modules not yet ported (Landings, Offers,
 * StreamTypes/StreamActions/StreamSchemas enum validation, trial-mode
 * limits, Grid/withStats, import/export, search, disable/enable, restore,
 * replace, currentLimitValues, createInCampaign, deleted/cleanArchive).
 *
 * StreamFilters (`stream_filters` rows) and Triggers ARE ported: nested
 * `filters: [...]`/`triggers: [...]` on `streams.create`/`streams.update`
 * are assigned via updateStreamAssociations() (mirrors legacy
 * `StreamService::_updateAssociations()`), and both associations are always
 * present on serialized streams (see serializeStream()) — see
 * StreamFiltersController (static filter-type catalogue,
 * `object=streamFilters.filters`) and TriggersController (target/condition/
 * action catalogues + `object=triggers.update`, also reused here for the
 * nested-triggers assign).
 */
class StreamsController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated from
    // CampaignsController rather than shared via inheritance, per the task
    // instructions (kept independent so as not to risk breaking the
    // already-implemented CampaignsController).
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

    /**
     * Streams have no ACL entity type of their own — access is always
     * checked through the parent campaign (§10.2: "Стримы всегда привязаны
     * к кампании — доступ проверяется через ACL родительской кампании
     * (isEditAllowed($campaign)/isViewAllowed($campaign)), не напрямую по
     * стриму."). This resolves that parent Campaign for a given Stream.
     */
    private function parentCampaign(Stream $stream): ?Campaign
    {
        return $stream->campaign ?? Campaign::find($stream->campaign_id);
    }

    // ---------------------------------------------------------------
    // action_options JSON-string <-> array (§8 "JSON внутри поля модели").
    // `streams.action_options` is a TEXT column holding a JSON string.
    // Deliberately done here (not via Stream::$casts) per task instructions,
    // to avoid touching the model while another agent may depend on it.
    // ---------------------------------------------------------------

    /** Mirrors `BaseStream::getActionOptions()` — decode on read. */
    private function decodeActionOptions($raw)
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    /**
     * Mirrors the old `DataConverterService::convertToType()` fallback
     * ("if is_array($value) return json_encode($value)") that old code
     * relies on to persist `action_options` as a JSON string — encode on
     * write.
     */
    private function encodeActionOptionsForWrite($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    // ---------------------------------------------------------------
    // Serialization (§8 + StreamSerializer::extra()/_addAssociation()).
    // ---------------------------------------------------------------

    /**
     * Mirrors `Component\StreamFilters\Serializer\StreamFilterSerializer`
     * ($_fields = true + `extra()`): all raw model fields, `payload`
     * JSON-decoded (legacy `Traffic\Model\StreamFilter::getPayload()`), and
     * the two legacy alias names normalized back to "uniqueness" on read
     * (`uniqueness_cookie`/`uniqueness_ip` -> `uniqueness`).
     */
    private function serializeStreamFilter(StreamFilter $filter): array
    {
        $data = $filter->getAttributes();

        if (array_key_exists('payload', $data)) {
            $data['payload'] = $this->decodeActionOptions($data['payload']);
        }

        if (in_array($data['name'] ?? null, ['uniqueness_cookie', 'uniqueness_ip'], true)) {
            $data['name'] = 'uniqueness';
        }

        $data['oid'] = $data['id'];

        return $data;
    }

    /**
     * Mirrors `Component\Landings\Serializer\StreamLandingAssociationSerializer`
     * / `Component\Offers\Serializer\StreamOfferAssociationSerializer` —
     * both are pure `$_fields = true` + `_flatTimestamps()`, no other
     * per-field logic, so one shared helper covers both association types.
     */
    private function serializeStreamAssociation(StreamLandingAssociation|StreamOfferAssociation $assoc): array
    {
        $data = $assoc->getAttributes();

        foreach (['created_at', 'updated_at'] as $key) {
            if (isset($data[$key]) && $data[$key] instanceof \DateTimeInterface) {
                $data[$key] = Carbon::instance($data[$key])->toDateTimeString();
            }
        }

        return $data;
    }

    private function serializeStream(Stream $stream): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()` — the
        // "$_fields = true" pass-through), same pattern as
        // CampaignsController::serializeCampaign().
        $stream->refresh();

        $data = $stream->getAttributes();

        if (array_key_exists('action_options', $data)) {
            $data['action_options'] = $this->decodeActionOptions($data['action_options']);
        }

        // getAttributes() bypasses Stream::$casts (boolean casts only apply
        // through attribute access), so cast the raw DB ints (0/1) here to
        // keep the API contract boolean, matching Stream::$casts.
        foreach (['collect_clicks', 'filter_or'] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = (bool) $data[$boolField];
            }
        }

        // StreamSerializer::extra() always unsets these (landing_id/offer_id
        // /status don't exist in our schema at all; updated_at does and must
        // be stripped explicitly per legacy behavior).
        unset($data['landing_id'], $data['offer_id'], $data['status'], $data['updated_at']);

        if (isset($data['created_at']) && $data['created_at'] instanceof \DateTimeInterface) {
            $data['created_at'] = Carbon::instance($data['created_at'])->toDateTimeString();
        }

        // StreamSerializer::_addAssociation() — always present, even empty
        // (§10.2: "⚠ починенный баг — раньше терялось при чтении").
        $data['filters'] = $stream->filters()->orderBy('id')->get()
            ->map(fn (StreamFilter $f) => $this->serializeStreamFilter($f))->values()->all();
        $data['triggers'] = $stream->triggers()->orderBy('id')->get()
            ->map(fn (Trigger $t) => app(TriggersController::class)->serializeTrigger($t))->values()->all();
        $data['landings'] = $stream->landings()->orderBy('id')->get()
            ->map(fn (StreamLandingAssociation $a) => $this->serializeStreamAssociation($a))->values()->all();
        $data['offers'] = $stream->offers()->orderBy('id')->get()
            ->map(fn (StreamOfferAssociation $a) => $this->serializeStreamAssociation($a))->values()->all();

        // TODO: `unread_events_count` (StreamEvents module, withEvents flag
        // on StreamSerializer) not ported yet.

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): array|Response
    {
        $campaignId = (int) $this->param($request, 'campaign_id');
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return $this->notFound('Campaign not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign\'s streams');
        }

        $streams = Stream::query()
            ->where('campaign_id', $campaign->id)
            ->where('state', '!=', 'deleted')
            // allOrderedStreamsForCampaign(): forced -> regular -> default.
            // Portable CASE expression (not MySQL-only FIELD()) so this also
            // works against the SQLite in-memory DB used by the test suite.
            ->orderByRaw("CASE `type` WHEN 'forced' THEN 0 WHEN 'regular' THEN 1 WHEN 'default' THEN 2 ELSE 3 END")
            ->orderBy('id')
            ->get();

        return $streams->map(fn (Stream $s) => $this->serializeStream($s))->all();
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Stream not found');
        }

        $stream = Stream::find((int) $id);

        if (! $stream) {
            return $this->notFound('Stream not found');
        }

        $campaign = $this->parentCampaign($stream);
        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this stream');
        }

        return response()->json($this->serializeStream($stream));
    }

    public function createAction(Request $request): Response
    {
        $params = $this->postParams($request);
        $campaignId = (int) ($params['campaign_id'] ?? 0);
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            return $this->notFound('Campaign not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to add streams to this campaign');
        }
        // TODO: trial-mode limits (checkTrialStreamFilters/checkTrialStream)
        // not ported yet.

        try {
            $result = $this->createStreamRecord($params, $campaign->id);
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        if (isset($result['errors'])) {
            return $this->validationError($result['errors']);
        }

        return response()->json($this->serializeStream($result['stream']));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Stream not found');
        }

        $stream = Stream::find((int) $id);

        if (! $stream) {
            return $this->notFound('Stream not found');
        }

        $campaign = $this->parentCampaign($stream);
        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to edit this stream');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);

        // TODO: trial-mode limits (checkTrialStreamFilters) not ported yet.

        try {
            $result = $this->updateStreamRecord($stream, $params);
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        if (isset($result['errors'])) {
            return $this->validationError($result['errors']);
        }

        return response()->json($this->serializeStream($result['stream']));
    }

    /**
     * Legacy `deleteAction` — despite the name this ARCHIVES the stream(s)
     * (`StreamService::archiveStream()` sets `state = deleted` via
     * `EntityService::archive()`), it never physically deletes the row.
     * Physical deletion only happens via `cleanArchiveAction()`
     * (`PruneStreams::deleteAll()`), which is not ported (TODO).
     *
     * Legacy returns no body on success (`NULL` -> empty response body);
     * replicated here by returning `null`.
     */
    public function deleteAction(Request $request): ?Response
    {
        $ids = $this->param($request, 'ids');
        $id = $this->param($request, 'id');

        if ($id) {
            $ids = [$id];
        }

        if (empty($ids) || ! is_array($ids)) {
            return $this->notFound('Stream not found');
        }

        foreach ($ids as $streamId) {
            $stream = Stream::find((int) $streamId);

            if (! $stream) {
                return $this->notFound('Stream not found');
            }

            $campaign = $this->parentCampaign($stream);
            if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $campaign)) {
                return $this->forbidden('You are not allowed to delete this stream');
            }

            $stream->state = 'deleted';
            $stream->save();

            // TODO: CampaignService::resortStreams() (re-numbers `position`
            // for TYPE_POSITION campaigns after an archive) not ported yet
            // — Campaigns module doesn't have this helper.
        }

        return null;
    }

    public function listAsOptionsAction(Request $request): array
    {
        $exclude = (int) $this->param($request, 'exclude', 0);

        // TODO: ACL filtering (AclService::getAllowedCampaignIds) not ported
        // yet — legacy restricts to campaigns the current user may access;
        // here every non-deleted campaign's streams are included.
        $deletedCampaignIds = Campaign::query()->where('state', 'deleted')->pluck('id');

        $streams = Stream::query()
            ->where('id', '!=', $exclude)
            ->where('state', '!=', 'deleted')
            ->when(
                $deletedCampaignIds->isNotEmpty(),
                fn ($q) => $q->whereNotIn('campaign_id', $deletedCampaignIds)
            )
            ->orderBy('campaign_id')
            ->orderBy('position')
            ->get();

        $campaignNames = Campaign::query()->pluck('name', 'id');

        $items = [];
        foreach ($streams as $stream) {
            $items[] = [
                'id' => $stream->id,
                'group' => $campaignNames[$stream->campaign_id] ?? null,
                'name' => '['.$stream->id.'] '.$stream->name,
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Grid / withStats (§9). Real source read (not just the doc):
    // application/Component/EntityGrid/EntityGridFactory.php,
    // application/Component/Reports/Grid/ReportDefinition.php,
    // application/Component/Clicks/Grid/ClicksDefinition.php.
    //
    // NOTE: unlike Campaigns/Offers/Landings/TrafficSources, the legacy
    // codebase has NO `Component\Streams\Grid\StreamGridDefinition` class,
    // and the real `Component\Streams\Controller\StreamsController` (grepped
    // in full) has no `withStats`/`gridDefinition` action at all — only
    // listAsOptions/index/deleted/show/restore/create/update/delete/replace/
    // disable/enable/createInCampaign/search/currentLimitValues/import/
    // export/archive/cleanArchive. This withStatsAction/gridDefinitionAction
    // pair is therefore a NEW addition per the task brief, not a port —
    // modeled 1:1 on CampaignGridDefinition's minimal virtual-column shape
    // (id/name/state + the base ReportDefinition metrics) since Streams
    // stats come from the exact same `clicks` table shape as Campaigns,
    // just grouped by `stream_id` instead of `campaign_id`.
    // ---------------------------------------------------------------

    /**
     * See App\Services\Grid\EntityGridBuilder for the aggregation port and
     * its docblocks for every metric-SQL/meta.total/pagination deviation
     * from the real legacy `EntityGridFactory` found while reading it.
     *
     * ACL is enforced inside EntityGridBuilder::applyAcl() (called from
     * loadEntities()), fed by the `user:` param passed below — see
     * tests/Feature/GridAclTest.php for live coverage.
     */
    public function withStatsAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new EntityGridBuilder(
            entityClass: Stream::class,
            statsIdColumn: 'stream_id',
            entityFields: ['id', 'name', 'state', 'campaign_id', 'group_id'],
            user: $this->currentUserService->get(),
        );

        return $builder->build($params);
    }

    /**
     * NEW addition (no legacy StreamGridDefinition exists — see the section
     * docblock above). Column set mirrors CampaignsController::
     * gridDefinitionAction()'s minimal shape exactly: id/name/state entity
     * fields plus the base metrics EntityGridBuilder computes (clicks/
     * conversions/revenue/cost/profit, inherited unchanged from
     * ReportDefinition::initColumns() — identical widths/fraction_size to
     * the Campaigns columns, since it's the same SQL).
     */
    public function gridDefinitionAction(Request $request): array
    {
        return [
            'url' => '?object=streams.withStats',
            'details' => null,
            'range_intervals' => null,
            'columns' => [
                ['name' => 'id', 'type' => 'integer', 'title' => 'grid.id', 'th_title' => 'grid.id', 'sortable' => true, 'category' => 'data', 'width' => 50],
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
    // Create/update record-building — public so CampaignsController can
    // call these for nested `streams: [...]` create/update (see
    // CampaignsController::createAction/updateAction TODO).
    // ---------------------------------------------------------------

    /**
     * Mirrors `StreamService::create()`.
     *
     * @return array{stream?: Stream, errors?: array}
     */
    public function createStreamRecord(array $params, int $campaignId): array
    {
        $params['campaign_id'] = $campaignId;

        if (empty($params['type'])) {
            $params['type'] = 'regular';
        }

        if ($params['type'] === 'default') {
            $hasDefault = Stream::query()
                ->where('campaign_id', $campaignId)
                ->where('type', 'default')
                ->where('state', '!=', 'deleted')
                ->exists();

            if ($hasDefault) {
                return ['errors' => ['type' => ['Only one default stream is allowed per campaign.']]];
            }
        }

        if (($params['action_type'] ?? null) === 'do_nothing') {
            $params['action_payload'] = '';
        }

        $errors = $this->validateStreamParams($params);
        if (! empty($errors)) {
            return ['errors' => $errors];
        }

        $fill = $this->fillableParams($params);
        if (array_key_exists('action_options', $fill)) {
            $fill['action_options'] = $this->encodeActionOptionsForWrite($fill['action_options']);
        }

        $stream = new Stream();
        $stream->fill($fill);
        $stream->save();

        // Mirrors StreamService::_updateAssociations() — filters/triggers
        // only (landings/offers modules aren't ported yet, TODO).
        $assocErrors = $this->updateStreamAssociations($stream, $params);
        if ($assocErrors !== null) {
            return ['errors' => $assocErrors];
        }

        return ['stream' => $stream];
    }

    /**
     * Mirrors `StreamService::update()`.
     *
     * @return array{stream?: Stream, errors?: array}
     */
    public function updateStreamRecord(Stream $stream, array $params): array
    {
        if (($params['action_type'] ?? null) === 'do_nothing') {
            $params['action_payload'] = '';
        }

        $errors = $this->validateStreamParams($params, partial: true);
        if (! empty($errors)) {
            return ['errors' => $errors];
        }

        $fill = $this->fillableParams($params);
        if (array_key_exists('action_options', $fill)) {
            $fill['action_options'] = $this->encodeActionOptionsForWrite($fill['action_options']);
        }

        $stream->fill($fill);
        $stream->save();

        // Mirrors StreamService::_updateAssociations() (see createStreamRecord).
        $assocErrors = $this->updateStreamAssociations($stream, $params);
        if ($assocErrors !== null) {
            return ['errors' => $assocErrors];
        }

        return ['stream' => $stream];
    }

    // ---------------------------------------------------------------
    // Filters/Triggers association assign — mirrors StreamService::
    // _updateAssociations() calling StreamFilterService::assign()/
    // StreamTriggerService::assign() directly (those legacy services have
    // no dedicated "assign" controller of their own — StreamFiltersController
    // is a pure catalogue, and TriggersController::assignTriggers() is
    // reused here exactly like CampaignsController reuses
    // StreamsController::createStreamRecord()/updateStreamRecord()).
    // ---------------------------------------------------------------

    /**
     * @return array|null Field-keyed validation errors, or null on success.
     */
    private function updateStreamAssociations(Stream $stream, array $params): ?array
    {
        // Mirrors StreamService::_updateAssociations(): a TYPE_DEFAULT
        // stream never carries filters/triggers, regardless of what was
        // passed in.
        if ($stream->type === 'default') {
            $params['filters'] = [];
            $params['triggers'] = [];
        }

        // Mirrors StreamService::_updateAssociations(): a stream whose
        // `schema` is `action`/`redirect` (i.e. the action lives directly
        // on the stream row) never carries landing/offer associations —
        // those only apply to `schema=landings`/`schema=offers` streams
        // (see legacy `Traffic\Model\BaseStream::ACTION`/`::REDIRECT`).
        if (in_array($stream->schema, ['action', 'redirect'], true)) {
            $params['landings'] = [];
            $params['offers'] = [];
        }

        if (array_key_exists('filters', $params) && is_array($params['filters'])) {
            $result = $this->assignStreamFilters($stream, $params['filters']);
            if (isset($result['errors'])) {
                return $result['errors'];
            }
        }

        if (array_key_exists('triggers', $params) && is_array($params['triggers'])) {
            $result = app(TriggersController::class)->assignTriggers($stream, $params['triggers']);
            if (isset($result['errors'])) {
                return $result['errors'];
            }
        }

        if (array_key_exists('landings', $params) && is_array($params['landings'])) {
            $this->assignStreamLandings($stream, $params['landings']);
        }

        if (array_key_exists('offers', $params) && is_array($params['offers'])) {
            $this->assignStreamOffers($stream, $params['offers']);
        }

        return null;
    }

    /**
     * Mirrors `StreamFilterService::assign()`: update-by-id (scoped to this
     * stream) or create for each item, then delete any of the stream's
     * existing filters NOT present in the incoming list (full replace, no
     * diffing) — same shape as TriggersController::assignTriggers().
     *
     * @return array{filters?: \Illuminate\Support\Collection<int, StreamFilter>, errors?: array}
     */
    private function assignStreamFilters(Stream $stream, array $items): array
    {
        $keptIds = [];

        foreach ($items as $data) {
            if (! is_array($data)) {
                continue;
            }

            $errors = $this->validateFilterParams($data);
            if (! empty($errors)) {
                return ['errors' => $errors];
            }

            $filter = null;
            if (! empty($data['id'])) {
                $filter = StreamFilter::where('id', (int) $data['id'])
                    ->where('stream_id', $stream->id)
                    ->first();
            }

            if (! $filter) {
                $filter = new StreamFilter();
                $filter->stream_id = $stream->id;
            }

            $fill = array_intersect_key($data, array_flip((new StreamFilter())->getFillable()));
            if (array_key_exists('payload', $fill)) {
                $fill['payload'] = $this->encodeActionOptionsForWrite($fill['payload']);
            }

            $filter->fill($fill);
            $filter->stream_id = $stream->id;
            $filter->save();

            $keptIds[] = $filter->id;
        }

        // whereNotIn() with an empty array matches every row, so an empty
        // $items list correctly deletes ALL of the stream's existing
        // filters — matching legacy behavior when `filters` is present but
        // empty (or force-cleared for a TYPE_DEFAULT stream, see
        // updateStreamAssociations()).
        StreamFilter::where('stream_id', $stream->id)
            ->whereNotIn('id', $keptIds)
            ->delete();

        $filters = StreamFilter::where('stream_id', $stream->id)->orderBy('id')->get();

        return ['filters' => $filters];
    }

    /**
     * Mirrors `Component\Landings\Service\StreamLandingAssociationService::
     * assign()`: upsert keyed by the NATURAL pair (stream_id, landing_id)
     * — NOT by association `id`, unlike assignStreamFilters() above — then
     * delete any of the stream's existing landing associations not present
     * in the incoming list (full replace, no diffing). Items without a
     * `landing_id` are silently skipped (matches legacy
     * `if (!empty($data["landing_id"]))`), never a validation error.
     */
    private function assignStreamLandings(Stream $stream, array $items): void
    {
        $keptIds = [];

        foreach ($items as $data) {
            if (! is_array($data) || empty($data['landing_id'])) {
                continue;
            }

            $assoc = StreamLandingAssociation::where('stream_id', $stream->id)
                ->where('landing_id', (int) $data['landing_id'])
                ->first();

            if (! $assoc) {
                $assoc = new StreamLandingAssociation();
                $assoc->stream_id = $stream->id;
                $assoc->state = 'active';
            }

            $fill = array_intersect_key($data, array_flip((new StreamLandingAssociation())->getFillable()));
            $assoc->fill($fill);
            $assoc->stream_id = $stream->id;
            $assoc->save();

            $keptIds[] = $assoc->id;
        }

        StreamLandingAssociation::where('stream_id', $stream->id)
            ->whereNotIn('id', $keptIds)
            ->delete();
    }

    /**
     * Mirrors `Component\Offers\Service\StreamOfferAssociationService::
     * assign()` — same natural-key (stream_id, offer_id) upsert + full
     * replace as assignStreamLandings() above.
     */
    private function assignStreamOffers(Stream $stream, array $items): void
    {
        $keptIds = [];

        foreach ($items as $data) {
            if (! is_array($data) || empty($data['offer_id'])) {
                continue;
            }

            $assoc = StreamOfferAssociation::where('stream_id', $stream->id)
                ->where('offer_id', (int) $data['offer_id'])
                ->first();

            if (! $assoc) {
                $assoc = new StreamOfferAssociation();
                $assoc->stream_id = $stream->id;
                $assoc->state = 'active';
            }

            $fill = array_intersect_key($data, array_flip((new StreamOfferAssociation())->getFillable()));
            $assoc->fill($fill);
            $assoc->stream_id = $stream->id;
            $assoc->save();

            $keptIds[] = $assoc->id;
        }

        StreamOfferAssociation::where('stream_id', $stream->id)
            ->whereNotIn('id', $keptIds)
            ->delete();
    }

    /**
     * Mirrors `StreamFilterValidator`: required `mode` (`stream_id` is
     * always controller-injected, not re-checked here), `name` must be a
     * known filter type (StreamFiltersController::validNames() — the same
     * catalogue backing `object=streamFilters.filters`), and `payload` is
     * capped at the legacy `MAX_PAYLOAD_LENGTH` (65534, JSON-encoded).
     */
    private function validateFilterParams(array $params): array
    {
        $errors = [];

        if (! array_key_exists('mode', $params) || trim((string) $params['mode']) === '') {
            $errors['mode'] = ['The mode field is required.'];
        }

        if (array_key_exists('name', $params) && ! in_array($params['name'], StreamFiltersController::validNames(), true)) {
            $errors['name'] = ['Invalid filter name.'];
        }

        if (array_key_exists('payload', $params)) {
            $encoded = is_array($params['payload']) ? json_encode($params['payload']) : (string) $params['payload'];
            if (mb_strlen($encoded) > 65534) {
                $errors['payload'] = ['The payload field must not be greater than 65534 characters.'];
            }
        }

        return $errors;
    }

    // ---------------------------------------------------------------
    // Validation (§6: ValidationError -> 406 {field: ["message"]})
    // ---------------------------------------------------------------

    /**
     * Minimal port of `StreamValidator`: only the `required` rule on
     * `action_type`/`schema` is replicated (`campaign_id` is always
     * resolved/injected by the controller before this runs, so it's not
     * re-checked here). `name`/`action_payload` are validated by legacy
     * against a `state`/`type`/`action_type`/`schema` enum sourced from
     * StreamTypeRepository/StreamActionRepository/StreamSchemaRepository —
     * none of those reference modules are ported yet.
     * TODO: port lengthMax(name, 100) / lengthMax(action_payload, 16777215)
     * and the enum checks once those reference modules exist.
     */
    private function validateStreamParams(array $params, bool $partial = false): array
    {
        $errors = [];

        foreach (['action_type', 'schema'] as $field) {
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
        return array_intersect_key($params, array_flip((new Stream())->getFillable()));
    }
}
