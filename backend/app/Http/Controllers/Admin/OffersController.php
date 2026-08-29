<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\IncompatibleLocalFileException;
use App\Http\Controllers\Controller;
use App\Models\AffiliateNetwork;
use App\Models\Offer;
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
 * `Component\Offers\Controller\OffersController` +
 * `Component\Offers\Serializer\OfferSerializer` +
 * `Component\Offers\Service\OfferService` (old codebase:
 * application/Component/Offers/Controller/OffersController.php,
 * application/Component/Offers/Serializer/OfferSerializer.php,
 * application/Component/Offers/Service/OfferService.php,
 * application/Component/Offers/Validator/OfferValidator.php,
 * application/Component/Offers/Repository/OfferRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.3_offers.md,
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §5-8 (ACL/errors/params/serialization).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, listAsOptions) — see per-method TODOs for what still
 * depends on modules not yet ported (Groups, AffiliateNetworks,
 * Conversions/ConversionCapacity, LocalFile/preview upload, archive/clone/
 * restore/deleted/cleanArchive/saveNote/getCostTypes/withStats/download).
 */
class OffersController extends Controller
{
    /** Legacy `Traffic\Model\Offer::$_aclKey` — see AclService docblock. */
    private const ACL_KEY = 'offers';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
        private readonly LocalFileService $localFileService,
    ) {}

    /**
     * Port of `ActionableResourceTrait::_createResource()`/
     * `_updateResource()` local/preloaded branches — see
     * LandingsController::applyLocalFileType() for the identical Landings
     * port and full docblock; kept duplicated here rather than shared, same
     * pattern as the rest of this controller.
     *
     * @throws IncompatibleLocalFileException
     */
    private function applyLocalFileType(array $params, array $fill, ?Offer $existing): array
    {
        $type = array_key_exists('offer_type', $params) ? $params['offer_type'] : $existing?->offer_type;

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
    // CampaignsController/StreamsController rather than shared via
    // inheritance, per the task instructions (kept independent so as not to
    // risk breaking the already-implemented controllers).
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

    // ---------------------------------------------------------------
    // action_options JSON-string <-> array (§8 "JSON внутри поля модели").
    // `offers.action_options` is a TEXT column holding a JSON string.
    // Deliberately done here (not via Offer::$casts) per task instructions,
    // same pattern as StreamsController.
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
    // Serialization (§8 + OfferSerializer::extra()).
    // ---------------------------------------------------------------

    private function serializeOffer(Offer $offer, bool $withGroupName = false): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()`), same
        // pattern as CampaignsController::serializeCampaign() /
        // StreamsController::serializeStream().
        $offer->refresh();

        $data = $offer->getAttributes();

        if (array_key_exists('action_options', $data)) {
            $data['action_options'] = $this->decodeActionOptions($data['action_options']);
        }

        // getAttributes() bypasses Offer::$casts (boolean casts only apply
        // through attribute access) — cast the raw DB ints (0/1) here to
        // keep the API contract boolean, matching Offer::$casts.
        foreach (['payout_auto', 'payout_upsell', 'conversion_cap_enabled'] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = (bool) $data[$boolField];
            }
        }

        // OfferSerializer::extra(): "group"/"affiliate_network" keys are
        // only present with ?withGroupName=1. Legacy `OfferRepository::
        // allWithGroupNames()` LEFT JOINs affiliate_networks (and groups)
        // and selects `networks.name AS affiliate_network` — replicated
        // here via a plain lookup (AffiliateNetworks module is now ported;
        // Groups is still a TODO stub).
        if ($withGroupName) {
            // TODO: GroupsRepository not ported yet.
            $data['group'] = null;
            $data['affiliate_network'] = ! empty($data['affiliate_network_id'])
                ? AffiliateNetwork::find($data['affiliate_network_id'])?->name
                : null;
        }

        // Legacy: a NULL affiliate_network_id is normalized to 0.
        if (array_key_exists('affiliate_network_id', $data) && $data['affiliate_network_id'] === null) {
            $data['affiliate_network_id'] = 0;
        }

        // OfferSerializer::extra(): local_file offers get a `preview` field
        // (OfferService::addPreviewData()) — TODO: LocalFile/PreviewImage
        // modules (ZIP upload, preview generation) not ported yet, left as
        // an explicit null stub per task instructions.
        if (($data['action_type'] ?? null) === 'local_file') {
            $data['preview'] = null;
        }

        // OfferSerializer::extra(): conversion_cap_enabled offers get a
        // computed `conversion_cap` field (ConversionCapacityRepository) —
        // TODO: Conversions/ConversionCapacity module not ported yet.
        if (! empty($data['conversion_cap_enabled'])) {
            $data['conversion_cap'] = null;
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
        $offers = Offer::query()
            ->where('state', '!=', 'deleted')
            ->orderBy('id')
            ->get();

        $offers = $this->aclService->filterByAcl($offers, false, $this->currentUserService->get());

        return array_values(array_map(fn (Offer $o) => $this->serializeOffer($o, $withGroupName), $offers));
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Offer not found');
        }

        $offer = Offer::find((int) $id);

        if (! $offer) {
            return $this->notFound('Offer not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $offer)) {
            return $this->forbidden('You are not allowed to view this offer');
        }

        $withGroupName = $this->boolParam($this->param($request, 'withGroupName'));

        return response()->json($this->serializeOffer($offer, $withGroupName));
    }

    public function createAction(Request $request): Response
    {
        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), self::ACL_KEY)) {
            return $this->forbidden('You are not allowed to create offers');
        }

        $params = $this->postParams($request);
        $errors = $this->validateOfferParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);

        try {
            $fill = $this->applyLocalFileType($params, $fill, null);
        } catch (IncompatibleLocalFileException $e) {
            return $this->validationError(['archive' => [$e->getMessage()]]);
        }

        if (array_key_exists('action_options', $fill)) {
            $fill['action_options'] = $this->encodeActionOptionsForWrite($fill['action_options']);
        }

        $offer = new Offer();
        $offer->fill($fill);

        try {
            $offer->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // TODO: `offer->isLocal()` preview generation (LocalFile/
        // PreviewImageService) not ported yet.

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addAuthorPermission($this->currentUserService->get(), $offer);

        return response()->json($this->serializeOffer($offer, true));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Offer not found');
        }

        $offer = Offer::find((int) $id);

        if (! $offer) {
            return $this->notFound('Offer not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $offer)) {
            return $this->forbidden('You are not allowed to edit this offer');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateOfferParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);

        try {
            $fill = $this->applyLocalFileType($params, $fill, $offer);
        } catch (IncompatibleLocalFileException $e) {
            return $this->validationError(['archive' => [$e->getMessage()]]);
        }

        if (array_key_exists('action_options', $fill)) {
            $fill['action_options'] = $this->encodeActionOptionsForWrite($fill['action_options']);
        }

        $offer->fill($fill);

        try {
            $offer->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // TODO: `offer->isLocal()` preview generation not ported yet.

        return response()->json($this->serializeOffer($offer));
    }

    public function listAsOptionsAction(Request $request): array
    {
        // allActive(): state == active.
        $offers = Offer::query()->where('state', 'active')->orderBy('id')->get();
        $offers = $this->aclService->filterByAcl($offers, false, $this->currentUserService->get());

        // Mirrors `Core\Entity\ListOptions\Builder::build()`: "offers" is a
        // GROUP_ENTITY_TYPE (see AclService::GROUP_ENTITY_TYPES), so
        // group_id/group are always included. `id`/`value` both carry the
        // numeric id for API-contract compatibility with the other
        // *Controller::listAsOptionsAction ports (Campaigns/Streams).
        $items = [];
        foreach ($offers as $offer) {
            // TODO: GroupsRepository not ported yet — real group name
            // resolution pending.
            $items[] = [
                'id' => $offer->id,
                'value' => $offer->id,
                'name' => $offer->name,
                'group_id' => $offer->group_id,
                'group' => null,
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Grid / withStats (§9). Real source read (not just the doc):
    // application/Component/EntityGrid/EntityGridFactory.php,
    // application/Component/Reports/Grid/ReportDefinition.php,
    // application/Component/Clicks/Grid/ClicksDefinition.php,
    // application/Component/Offers/Grid/OfferGridDefinition.php.
    // ---------------------------------------------------------------

    /**
     * Legacy `offers.withStats` -> `OfferRepository::allWithStats()` ->
     * `Component\EntityGrid\EntityGridFactory::build()`, same shape as
     * `campaigns.withStats`, just grouped by `offer_id`. See
     * App\Services\Grid\EntityGridBuilder for the port and its docblocks
     * for every deviation from the real legacy source (metric SQL,
     * meta.total shape, pagination semantics).
     *
     * TODO: ACL not wired here yet (same TODO as
     * CampaignsController::withStatsAction()).
     */
    public function withStatsAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new EntityGridBuilder(
            entityClass: Offer::class,
            statsIdColumn: 'offer_id',
            entityFields: ['id', 'name', 'state', 'group_id'],
            user: $this->currentUserService->get(),
        );

        return $builder->build($params);
    }

    /**
     * Legacy `offers.gridDefinition` -> `OfferGridDefinition::
     * getGridDefinition()`. Minimal column set per the task brief (same
     * scope decision as CampaignsController::gridDefinitionAction()): the
     * entity's own virtual `id`/`name` columns (OfferGridDefinition.php —
     * both declared with `"virtual" => true`; note the real
     * OfferGridDefinition does NOT add a virtual `state` column at all,
     * unlike CampaignGridDefinition — confirmed by reading the class, not
     * assumed) plus the base metrics EntityGridBuilder computes (clicks/
     * conversions/revenue/cost/profit, inherited unchanged from
     * ReportDefinition::initColumns() — identical inner_select SQL, so
     * identical widths/fraction_size to CampaignsController's columns).
     * `checkbox`/`country`/`group`/`affiliate_network`/`notes`/
     * `conversion_cap` are legacy UI-only (template cells, or backed by
     * not-yet-ported Groups/LocalFile/ConversionCapacity modules) or out of
     * scope for this round — same exclusion rationale as
     * CampaignsController leaving out `checkbox`/`ts`/`streams_count`/
     * `more`.
     *
     * `range_intervals: null`: OfferGridDefinition extends ReportDefinition,
     * which redeclares `protected $_rangeIntervals = NULL;` — inherited
     * unchanged, same as CampaignGridDefinition.
     */
    public function gridDefinitionAction(Request $request): array
    {
        return [
            'url' => '?object=offers.withStats',
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
     * Minimal port of `OfferValidator`: only `required`/`lengthMax` on
     * `name` are replicated (same scope decision as
     * CampaignsController::validateCampaignParams() /
     * StreamsController::validateStreamParams()). NOT ported (TODO):
     * uniqueness(name), in(offer_type/payout_type/state) enum checks
     * against StreamActionCategory/Offer::getValidPayoutTypes()/State, and
     * OfferService::_checkAlternativeOffer() (conversion_cap_enabled
     * requires alternative_offer_id) — none of the reference
     * modules/enums those depend on are ported yet.
     */
    private function validateOfferParams(array $params, bool $partial = false): array
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
        return array_intersect_key($params, array_flip((new Offer())->getFillable()));
    }
}
