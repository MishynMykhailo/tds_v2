<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\IncompatibleLocalFileException;
use App\Http\Controllers\Controller;
use App\Models\Landing;
use App\Services\AclService;
use App\Services\CurrentUserService;
use App\Services\Grid\EntityGridBuilder;
use App\Services\Grid\QueryParams;
use App\Services\LocalFileService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Landings\Controller\LandingsController` +
 * `Component\Landings\Serializer\LandingSerializer` +
 * `Component\Landings\Service\LandingService` (old codebase:
 * application/Component/Landings/Controller/LandingsController.php,
 * application/Component/Landings/Serializer/LandingSerializer.php,
 * application/Component/Landings/Service/LandingService.php,
 * application/Component/Landings/Validator/LandingValidator.php,
 * application/Component/Landings/Repository/LandingRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.4_landings.md,
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §5-8 (ACL/errors/params/serialization).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, listAsOptions) — see per-method TODOs for what still
 * depends on modules not yet ported (Groups, preview-image generation,
 * demo-mode archive check, archive/clone/restore/deleted/cleanArchive/
 * saveNote/withStats/download). LocalFile/ZIP-upload folder handling IS
 * now ported — see applyLocalFileType() + App\Services\LocalFileService.
 */
class LandingsController extends Controller
{
    /** Legacy `Traffic\Model\Landing::$_aclKey` — see AclService docblock. */
    private const ACL_KEY = 'landings';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
        private readonly LocalFileService $localFileService,
        private readonly \App\Services\PreviewUrlBuilder $previewUrlBuilder,
    ) {}

    /**
     * Port of `ActionableResourceTrait::_createResource()`/
     * `_updateResource()` local/preloaded branches — the only two branches
     * that trait touches (the "external"/default type is left untouched by
     * legacy too, so `$fill['action_type']` from the raw request survives
     * as-is in that case). `$existing` is null on create.
     *
     * @throws IncompatibleLocalFileException
     */
    private function applyLocalFileType(array $params, array $fill, ?Landing $existing): array
    {
        $type = array_key_exists('landing_type', $params) ? $params['landing_type'] : $existing?->landing_type;

        if ($type === 'local') {
            $existingOptions = $existing ? $this->decodeActionOptions($existing->action_options) : null;
            $folder = $existingOptions['folder'] ?? null;

            if (! $folder) {
                $folder = $this->localFileService->generateUniqueFolder(
                    (string) ($params['name'] ?? $existing?->name ?? '')
                );
            }

            if (! empty($params['archive'])) {
                $this->localFileService->replaceFiles($folder, $params['archive']);
            }

            $fill['action_type'] = 'local_file';
            $fill['action_options'] = ['folder' => $folder];
        } elseif ($type === 'preloaded') {
            $fill['action_type'] = 'curl';
        }

        return $fill;
    }

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated from
    // CampaignsController/StreamsController/OffersController rather than
    // shared via inheritance, per the task instructions (kept independent
    // so as not to risk breaking the already-implemented controllers).
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
    // action_options JSON-string <-> array (§8 "JSON внутри поля модели").
    // `landings.action_options` is a TEXT column holding a JSON string.
    // Deliberately done here (not via Landing::$casts) per task
    // instructions, same pattern as StreamsController/OffersController.
    // ---------------------------------------------------------------

    private function decodeActionOptions($raw)
    {
        return is_string($raw) ? json_decode($raw, true) : $raw;
    }

    private function encodeActionOptionsForWrite($value)
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    // ---------------------------------------------------------------
    // Serialization (§8 + LandingSerializer::extra()).
    // ---------------------------------------------------------------

    private function serializeLanding(Landing $landing, bool $withGroupName = false): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()`), same
        // pattern as the other ported controllers.
        $landing->refresh();

        $data = $landing->getAttributes();

        if (array_key_exists('action_options', $data)) {
            $data['action_options'] = $this->decodeActionOptions($data['action_options']);
        }

        // LandingSerializer::extra(): "group" key is only present with
        // ?withGroupName=1. Legacy `LandingRepository::allWithGroupName()`
        // LEFT JOINs `groups` and selects `groups.name AS \`group\`` — a
        // plain LEFT JOIN, no "empty group_id -> Default" fallback (that's
        // `CampaignSerializer`-only, confirmed by reading both sources).
        if ($withGroupName) {
            $data['group'] = ! empty($data['group_id'])
                ? \App\Models\Group::find($data['group_id'])?->name
                : null;
        }

        // LandingSerializer::extra(): local_file landings get a `preview`
        // field. Port of `ActionableResourceTrait::addPreviewData()` —
        // literal legacy behavior: the relative path is ALWAYS set once a
        // folder exists, regardless of whether `_preview.png` has
        // actually been generated yet (`App\Jobs\
        // GenerateLocalFilePreviewJob`, queued from
        // `EditorController::saveFileDataAction()`/`removeFileAction()` —
        // see that job's docblock). Not a broken link: a landing/offer
        // whose file was never edited after this feature shipped simply
        // has no image at that path yet.
        $folder = is_array($data['action_options'] ?? null) ? ($data['action_options']['folder'] ?? null) : null;
        if (($data['action_type'] ?? null) === 'local_file' && ! empty($folder)) {
            $data['preview'] = rtrim($folder, '/').'/'.\App\Services\PreviewImageService::PREVIEW_FILE;
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
        $withGroupName = $this->boolParam($this->param($request, 'withGroupName'));

        // allNotDeletedWithRelations(): state != deleted, ordered by id.
        $landings = Landing::query()
            ->where('state', '!=', 'deleted')
            ->orderBy('id')
            ->get();

        $landings = $this->aclService->filterByAcl($landings, false, $this->currentUserService->get());

        return array_values(array_map(fn (Landing $l) => $this->serializeLanding($l, $withGroupName), $landings));
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Landing not found');
        }

        $landing = Landing::find((int) $id);

        if (! $landing) {
            return $this->notFound('Landing not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $landing)) {
            return $this->forbidden('You are not allowed to view this landing');
        }

        return response()->json($this->serializeLanding($landing));
    }

    /**
     * New action (no direct legacy equivalent that was ever actually
     * built — see `docs/default/TODO_IMPROVEMENTS.md` in the legacy
     * source, "[НЕ СДЕЛАНО] Превью оффера/лендинга прямо из админки":
     * documented as an idea, never implemented there). Mints a short-
     * lived HMAC-signed link to `traffic-core/public/preview.php`,
     * which renders this landing's `local_file` content directly (no
     * campaign/stream resolution) — see that file's docblock for the
     * token scheme and why the two projects can't share code directly.
     *
     * Admin-gated the same way `showAction()` is (`isViewAllowed()`) —
     * the frontend "Preview" eye-icon button should call this to get a
     * URL to open in a new tab, not use `local_path` directly (that
     * field still doesn't exist on this model/serializer — see
     * `serializeLanding()`'s `preview` field for the SEPARATE screenshot-
     * thumbnail feature, not this one).
     */
    public function previewAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Landing not found');
        }

        $landing = Landing::find((int) $id);

        if (! $landing) {
            return $this->notFound('Landing not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $landing)) {
            return $this->forbidden('You are not allowed to preview this landing');
        }

        if ($landing->action_type !== 'local_file') {
            return $this->validationError(['action_type' => ['Only local_file landings can be previewed']]);
        }

        return response()->json(['url' => $this->previewUrlBuilder->build('landing', $landing->id)]);
    }

    public function createAction(Request $request): Response
    {
        // TODO: demo-mode archive-upload deny (ConfigService::isDemo() +
        // !empty($data["archive"])) not ported yet — no demo/config module.

        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), self::ACL_KEY)) {
            return $this->forbidden('You are not allowed to create landings');
        }

        $params = $this->postParams($request);
        $errors = $this->validateLandingParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        // `landings.state` has no DB-level default (unlike campaigns/
        // streams) — found live via tests-contract/: a freshly created
        // landing with no explicit `state` param silently got `state =
        // NULL`, invisible to every `WHERE state = 'active'`/`!= 'deleted'`
        // listing query afterward. Legacy always creates as 'active'.
        $fill['state'] ??= 'active';

        try {
            $fill = $this->applyLocalFileType($params, $fill, null);
        } catch (IncompatibleLocalFileException $e) {
            return $this->validationError(['archive' => [$e->getMessage()]]);
        }

        if (array_key_exists('action_options', $fill)) {
            $fill['action_options'] = $this->encodeActionOptionsForWrite($fill['action_options']);
        }

        $landing = new Landing();
        $landing->fill($fill);

        try {
            $landing->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // TODO: `landing->isLocal()` preview generation (LocalFile/
        // PreviewImageService) not ported yet.

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addAuthorPermission($this->currentUserService->get(), $landing);

        return response()->json($this->serializeLanding($landing, true));
    }

    public function updateAction(Request $request): Response
    {
        // TODO: demo-mode archive-upload deny not ported yet (see createAction).

        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Landing not found');
        }

        $landing = Landing::find((int) $id);

        if (! $landing) {
            return $this->notFound('Landing not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $landing)) {
            return $this->forbidden('You are not allowed to edit this landing');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateLandingParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);

        try {
            $fill = $this->applyLocalFileType($params, $fill, $landing);
        } catch (IncompatibleLocalFileException $e) {
            return $this->validationError(['archive' => [$e->getMessage()]]);
        }

        if (array_key_exists('action_options', $fill)) {
            $fill['action_options'] = $this->encodeActionOptionsForWrite($fill['action_options']);
        }

        $landing->fill($fill);

        try {
            $landing->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // TODO: `landing->isLocal()` preview generation not ported yet.

        return response()->json($this->serializeLanding($landing, true));
    }

    public function listAsOptionsAction(Request $request): array
    {
        // allActive(): state == active.
        $landings = Landing::query()->where('state', 'active')->orderBy('id')->get();
        $landings = $this->aclService->filterByAcl($landings, false, $this->currentUserService->get());

        // Mirrors `Core\Entity\ListOptions\Builder::build()`: "landings" is
        // a GROUP_ENTITY_TYPE (see AclService::GROUP_ENTITY_TYPES), so
        // group_id/group are always included. `id`/`value` both carry the
        // numeric id for API-contract compatibility with the other
        // *Controller::listAsOptionsAction ports (Campaigns/Streams/Offers).
        // Real group name lookup (see serializeLanding()'s docblock for
        // the same "no Default fallback" note — `Builder::build()`'s
        // behavior, confirmed by reading it, not assumed).
        $groupNames = \App\Models\Group::whereIn('id', collect($landings)->pluck('group_id')->filter()->unique())
            ->pluck('name', 'id');

        $items = [];
        foreach ($landings as $landing) {
            $items[] = [
                'id' => $landing->id,
                'value' => $landing->id,
                'name' => $landing->name,
                'group_id' => $landing->group_id,
                'group' => ! empty($landing->group_id) ? $groupNames->get($landing->group_id) : null,
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Grid / withStats (§9). Real source read (not just the doc):
    // application/Component/EntityGrid/EntityGridFactory.php,
    // application/Component/Reports/Grid/ReportDefinition.php,
    // application/Component/Clicks/Grid/ClicksDefinition.php,
    // application/Component/Landings/Grid/LandingGridDefinition.php.
    // ---------------------------------------------------------------

    /**
     * Legacy `landings.withStats` -> `LandingRepository::allWithStats()` ->
     * `Component\EntityGrid\EntityGridFactory::build()`, same shape as
     * `campaigns.withStats`, just grouped by `landing_id`. See
     * App\Services\Grid\EntityGridBuilder for the port and its docblocks
     * for every deviation from the real legacy source (metric SQL,
     * meta.total shape, pagination semantics).
     *
     * ACL is enforced inside EntityGridBuilder::applyAcl() (called from
     * loadEntities()), fed by the `user:` param passed below — see
     * tests/Feature/GridAclTest.php for live coverage.
     */
    public function withStatsAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new EntityGridBuilder(
            entityClass: Landing::class,
            statsIdColumn: 'landing_id',
            entityFields: ['id', 'name', 'state', 'group_id'],
            user: $this->currentUserService->get(),
        );

        return $builder->build($params);
    }

    /**
     * Legacy `landings.gridDefinition` -> `LandingGridDefinition::
     * getGridDefinition()`. Minimal column set per the task brief (same
     * scope decision as CampaignsController::gridDefinitionAction()): the
     * entity's own virtual `id`/`name` columns (LandingGridDefinition.php —
     * both declared with `"virtual" => true`; note the real
     * LandingGridDefinition does NOT add a virtual `state` column at all,
     * unlike CampaignGridDefinition — confirmed by reading the class, not
     * assumed) plus the base metrics EntityGridBuilder computes (clicks/
     * conversions/revenue/cost/profit, inherited unchanged from
     * ReportDefinition::initColumns() — identical inner_select SQL, so
     * identical widths/fraction_size to CampaignsController's columns).
     * `checkbox`/`group`/`notes` are legacy UI-only (template cells, or
     * backed by the not-yet-ported Groups module) or out of scope for this
     * round — same exclusion rationale as CampaignsController leaving out
     * `checkbox`/`ts`/`streams_count`/`more`.
     *
     * `range_intervals: null`: LandingGridDefinition extends
     * ReportDefinition, which redeclares `protected $_rangeIntervals =
     * NULL;` — inherited unchanged, same as CampaignGridDefinition.
     */
    public function gridDefinitionAction(Request $request): array
    {
        return [
            'url' => '?object=landings.withStats',
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
     * Minimal port of `LandingValidator`: only `required`/`lengthMax` on
     * `name` are replicated (same scope decision as
     * CampaignsController::validateCampaignParams() /
     * StreamsController::validateStreamParams() /
     * OffersController::validateOfferParams()). NOT ported (TODO):
     * uniqueness(name)/uniqueness(folder)/lengthMax(folder)/slug(folder)
     * (folder generation itself is now ported, see applyLocalFileType() +
     * App\Services\LocalFileService — but these specific validator rules
     * aren't reproduced),
     * in(landing_type) enum check against StreamActionCategoryRepository —
     * none of the reference modules those depend on are ported yet.
     */
    private function validateLandingParams(array $params, bool $partial = false): array
    {
        $errors = [];

        $present = array_key_exists('name', $params);
        $empty = $present && trim((string) $params['name']) === '';

        if ((! $partial && (! $present || $empty)) || ($partial && $present && $empty)) {
            $errors['name'] = ['The name field is required.'];
        } elseif ($present && ! $empty && mb_strlen((string) $params['name']) > 100) {
            $errors['name'] = ['The name field must not be greater than 100 characters.'];
        }

        return $errors;
    }

    private function fillableParams(array $params): array
    {
        return array_intersect_key($params, array_flip((new Landing())->getFillable()));
    }
}
