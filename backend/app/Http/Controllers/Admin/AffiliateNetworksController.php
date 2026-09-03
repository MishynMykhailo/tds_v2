<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AffiliateNetwork;
use App\Models\Offer;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\AffiliateNetworks\Controller\AffiliateNetworksController` +
 * `Component\AffiliateNetworks\Serializer\AffiliateNetworkSerializer` +
 * `Component\AffiliateNetworks\Service\AffiliateNetworkService` (old
 * codebase:
 * application/Component/AffiliateNetworks/Controller/AffiliateNetworksController.php,
 * application/Component/AffiliateNetworks/Serializer/AffiliateNetworkSerializer.php,
 * application/Component/AffiliateNetworks/Service/AffiliateNetworkService.php,
 * application/Component/AffiliateNetworks/Validator/AffiliateNetworkValidator.php,
 * application/Component/AffiliateNetworks/Repository/AffiliateNetworksRepository.php).
 *
 * NOTE on the `?object=` key: the legacy `Component\AffiliateNetworks\
 * Initializer::loadControllers()` registers this controller under the
 * exact key `"affiliateNetworks"` (camelCase) — confirmed by reading that
 * Initializer directly, and independently corroborated by
 * tests-contract/tests/AffiliateNetworksTest.php's docblock ("Confirmed
 * live: `?object=affiliateNetworks.index` returns 200, not 404"). This is
 * NOT the same string as the `affiliate_networks` ACL key
 * (`Traffic\Model\AffiliateNetwork::$_aclKey`, see AclService::ACL_KEYS) —
 * those are two independently-confirmed, differently-cased identifiers.
 * ObjectDispatchController lowercases the incoming controller name before
 * lookup, so it is registered there as `'affiliatenetworks'`, matching the
 * existing all-lowercase-no-separator convention already used for every
 * other multi-word module key (`trafficsources`, `streamfilters`,
 * `streamactions`, `streamtypes`, `streamschemas`, `favouritestreams`,
 * `streamevents`, `userpreferences`, `apikeys`).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, listAsOptions) — matches the scope already ported for
 * TrafficSourcesController/OffersController/LandingsController (NOT
 * ported: withStats/archive/clone/restore/deleted/cleanArchive/saveNote/
 * gridDefinition).
 */
class AffiliateNetworksController extends Controller
{
    /** Legacy `Traffic\Model\AffiliateNetwork::$_aclKey` — see AclService docblock. */
    private const ACL_KEY = 'affiliate_networks';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers — duplicated from
    // TrafficSourcesController/OffersController/LandingsController rather
    // than shared via inheritance, per the same scope decision as those
    // controllers (kept independent so as not to risk breaking them).
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

    /** `Core\Exceptions\DenyError` shape: 403, {"error": "..."}. */
    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // `pull_api_options` JSON-string <-> array. `affiliate_networks.
    // pull_api_options` is a TEXT column holding a JSON string (see
    // legacy `Traffic\Model\AffiliateNetwork::getPullApiMacros()` /
    // `application/migrations2/20200204141227_update_apps_flyer_
    // integration.php`, which `json_decode()`s it manually) — decoded/
    // encoded here rather than via `AffiliateNetwork::$casts`, same
    // pattern as TrafficSourcesController's `parameters`/
    // `postback_statuses` handling.
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
    // Serialization. `AffiliateNetworkSerializer` has `$_fields = true`
    // (every raw model field passes through) plus an `extra()` hook that,
    // only when `$_extended` is true (indexAction passes `true`,
    // showAction/createAction/updateAction pass the `false` default),
    // adds an `offers` key = count of active offers referencing this
    // network (`OfferRepository::countActive("affiliate_network_id = ".$id)`,
    // i.e. `state = 'active' AND affiliate_network_id = $id`).
    // ---------------------------------------------------------------

    private function serializeAffiliateNetwork(AffiliateNetwork $network, bool $extended = false): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()`), same
        // pattern as the other ported controllers.
        $network->refresh();

        $data = $network->getAttributes();

        if (array_key_exists('pull_api_options', $data)) {
            $data['pull_api_options'] = $this->decodeJsonField($data['pull_api_options']);
        }

        foreach (['created_at', 'updated_at'] as $key) {
            if (isset($data[$key]) && $data[$key] instanceof \DateTimeInterface) {
                $data[$key] = Carbon::instance($data[$key])->toDateTimeString();
            }
        }

        if ($extended) {
            $data['offers'] = Offer::query()
                ->where('state', 'active')
                ->where('affiliate_network_id', $network->id)
                ->count();
        }

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): array
    {
        // Legacy `allActive()`: state == active.
        $networks = AffiliateNetwork::query()
            ->where('state', 'active')
            ->orderBy('id')
            ->get();

        $networks = $this->aclService->filterByAcl($networks, false, $this->currentUserService->get());

        return array_values(array_map(
            fn (AffiliateNetwork $n) => $this->serializeAffiliateNetwork($n, true),
            $networks
        ));
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Affiliate network not found');
        }

        $network = AffiliateNetwork::find((int) $id);

        if (! $network) {
            return $this->notFound('Affiliate network not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $network)) {
            return $this->forbidden('You are not allowed to view this affiliate network');
        }

        return response()->json($this->serializeAffiliateNetwork($network));
    }

    public function createAction(Request $request): Response
    {
        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), self::ACL_KEY)) {
            return $this->forbidden('You are not allowed to create affiliate networks');
        }

        $params = $this->postParams($request);
        $errors = $this->validateAffiliateNetworkParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        // `affiliate_networks.state` has no DB-level default (unlike
        // campaigns/streams) — found live via tests-contract/: a freshly
        // created affiliate network with no explicit `state` param
        // silently got `state = NULL`, invisible to every listing query
        // afterward. Legacy always creates as 'active'.
        $fill['state'] ??= 'active';
        if (array_key_exists('pull_api_options', $fill)) {
            $fill['pull_api_options'] = $this->encodeJsonFieldForWrite($fill['pull_api_options']);
        }

        $network = new AffiliateNetwork();
        $network->fill($fill);

        try {
            $network->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addAuthorPermission($this->currentUserService->get(), $network);

        return response()->json($this->serializeAffiliateNetwork($network));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Affiliate network not found');
        }

        $network = AffiliateNetwork::find((int) $id);

        if (! $network) {
            return $this->notFound('Affiliate network not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $network)) {
            return $this->forbidden('You are not allowed to edit this affiliate network');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateAffiliateNetworkParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        if (array_key_exists('pull_api_options', $fill)) {
            $fill['pull_api_options'] = $this->encodeJsonFieldForWrite($fill['pull_api_options']);
        }

        $network->fill($fill);

        try {
            $network->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        return response()->json($this->serializeAffiliateNetwork($network));
    }

    public function listAsOptionsAction(Request $request): array
    {
        // allActive(): state == active.
        $networks = AffiliateNetwork::query()->where('state', 'active')->orderBy('id')->get();
        $networks = $this->aclService->filterByAcl($networks, false, $this->currentUserService->get());

        // Mirrors `Core\Entity\ListOptions\Builder::build($definition, $models,
        // ["template" => "template_name"])`. `affiliate_network` is NOT a
        // GROUP_ENTITY_TYPE (see AclService::GROUP_ENTITY_TYPES — only
        // campaigns/offers/landings), so no group_id/group keys here,
        // unlike Offers/Landings/Campaigns listAsOptionsAction ports.
        $items = [];
        foreach ($networks as $network) {
            $items[] = [
                'id' => $network->id,
                'value' => $network->id,
                'name' => $network->name,
                'template' => $network->template_name,
            ];
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Validation (§6: ValidationError -> 406 {field: ["message"]})
    // ---------------------------------------------------------------

    /**
     * Minimal port of `AffiliateNetworkValidator`: only `required`/
     * `lengthMax(100)` on `name` are replicated (same scope decision as
     * TrafficSourcesController::validateTrafficSourceParams() — note the
     * max length here is 100, per the real legacy validator, NOT 50 like
     * TrafficSource). NOT ported (TODO): uniqueness(name) — no reference
     * module for this ported yet.
     */
    private function validateAffiliateNetworkParams(array $params, bool $partial = false): array
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
        return array_intersect_key($params, array_flip((new AffiliateNetwork())->getFillable()));
    }
}
