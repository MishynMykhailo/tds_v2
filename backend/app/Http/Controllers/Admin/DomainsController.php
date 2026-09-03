<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Domain;
use App\Services\AclService;
use App\Services\CurrentUserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Domains\Controller\DomainsController` +
 * `Component\Domains\Serializer\DomainSerializer` +
 * `Component\Domains\Service\DomainService` (old codebase:
 * application/Component/Domains/Controller/DomainsController.php,
 * application/Component/Domains/Serializer/DomainSerializer.php,
 * application/Component/Domains/Service/DomainService.php,
 * application/Component/Domains/Validator/DomainValidator.php,
 * application/Component/Domains/Repository/DomainsRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.7_domains.md,
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §5-8 (ACL/errors/params/serialization).
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update, listAsOptions) — see per-method TODOs for what still
 * depends on modules not yet ported: `updateStatus` (DomainCheckerService —
 * real HTTP/Guzzle domain-check, `LIMIT_SSL_ATTEMPTS`, exponential backoff —
 * deliberately left unimplemented, out of scope per task instructions),
 * archive/clone/restore/deleted/cleanArchive/saveNote,
 * FeatureService::hasDomainsFeature() (multi-domain licensing gate — always
 * treated as unavailable, see createAction), punycode `xn--` decode/encode
 * (`TrueBV\Punycode`, no equivalent Composer package installed in this
 * project), DomainErrorsService (`error_solution` field).
 */
class DomainsController extends Controller
{
    /** Legacy `Traffic\Model\Domain::$_aclKey` — see AclService docblock. */
    private const ACL_KEY = 'domains';

    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated from
    // CampaignsController/StreamsController/OffersController/
    // LandingsController/TrafficSourcesController rather than shared via
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
    // Serialization (§8 + DomainSerializer::extra()).
    // ---------------------------------------------------------------

    private function serializeDomain(Domain $domain): array
    {
        // Reload straight from DB so every field reflects the raw column
        // value as stored (mirrors old `AbstractModel::getData()`), same
        // pattern as the other ported controllers.
        $domain->refresh();

        $data = $domain->getAttributes();

        // getAttributes() bypasses Domain::$casts (boolean casts only apply
        // through attribute access) — cast the raw DB ints (0/1) here to
        // keep the API contract boolean, matching Domain::$casts.
        foreach (['is_ssl', 'wildcard', 'catch_not_found', 'is_robots_allowed', 'ssl_redirect', 'allow_indexing'] as $boolField) {
            if (array_key_exists($boolField, $data)) {
                $data[$boolField] = (bool) $data[$boolField];
            }
        }

        // DomainSerializer::extra(): real (campaigns.domain_id-based) count
        // of campaigns using this domain — cheap enough to compute directly
        // rather than stub, unlike the Groups/AffiliateNetworks lookups in
        // OffersController/LandingsController.
        $data['campaigns_count'] = Campaign::query()->where('domain_id', $domain->id)->count();

        // DomainSerializer::extra(): resolved default campaign name.
        $data['default_campaign'] = '';
        if (! empty($data['default_campaign_id'])) {
            $campaign = Campaign::find($data['default_campaign_id']);
            if ($campaign) {
                $data['default_campaign'] = $campaign->name;
            }
        }

        // TODO: DomainErrorsService (human-readable hint per
        // error_description) not ported — always empty per legacy default.
        $data['error_solution'] = '';

        // TODO: punycode (`xn--...`) -> unicode decode of `name`
        // (TrueBV\Punycode) not ported — no equivalent Composer package
        // installed in this project. `name` is returned as stored.

        foreach (['created_at', 'updated_at', 'next_check_at'] as $key) {
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
        // Legacy `allActiveByLicenseType()`: state == active (the "isBasic
        // license limits to 1 row" branch of getAllAvailableDomains() is not
        // ported — no license/edition module here).
        $domains = Domain::query()
            ->where('state', 'active')
            ->orderBy('id')
            ->get();

        $domains = $this->aclService->filterByAcl($domains, false, $this->currentUserService->get());

        return array_values(array_map(fn (Domain $d) => $this->serializeDomain($d), $domains));
    }

    public function showAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Domain not found');
        }

        // Legacy `findActive($id)`: id match AND state == active (NOT a
        // plain find()) — an archived/deleted domain 404s here even if the
        // id exists.
        //
        // INTENTIONAL DEVIATION (verified live against the old backend by
        // the contract-test suite): legacy does NOT 404 here on a missing/
        // nonexistent id — `findActive()` silently returns null, and the
        // serializer runs on that null anyway, producing HTTP 200 with a
        // body missing id/name but still carrying a bogus non-zero
        // `campaigns_count` (a dictionary lookup keyed by the absent id
        // happens to hit the "no domain assigned" bucket). That's a clear
        // legacy bug, not a behavior worth preserving — a real 404 here is
        // objectively better and no real client depends on the broken
        // shape. Documented, not silently drifted.
        $domain = Domain::query()->where('id', (int) $id)->where('state', 'active')->first();

        if (! $domain) {
            return $this->notFound('Domain not found');
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $domain)) {
            return $this->forbidden('You are not allowed to view this domain');
        }

        return response()->json($this->serializeDomain($domain));
    }

    public function createAction(Request $request): Response
    {
        if (! $this->aclService->isCreateAllowed($this->currentUserService->get(), self::ACL_KEY)) {
            return $this->forbidden('You are not allowed to create domains');
        }

        $params = $this->postParams($request);

        // TODO: FeatureService::hasDomainsFeature() (multi-domain
        // licensing) not ported — always treated as unavailable, so only a
        // single domain can ever be created. This independent-of-the-flag
        // legacy behavior ("`name` is comma-split, first segment wins") IS
        // replicated, per task instructions, regardless of the licensing
        // gate around it.
        $rawName = (string) ($params['name'] ?? '');
        $params['name'] = explode(',', $rawName, 2)[0];

        $errors = $this->validateDomainParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        // Legacy `_findOldParams()` translation (redirect/is_robots_allowed)
        // + the "is_ssl always forced false on create" legacy quirk (see
        // class docblock / `_findOldParams()`'s `is_ssl` branch, which
        // evaluates true for every possible input value).
        $params = $this->translateLegacyFields($params);
        $params['is_ssl'] = false;

        $fill = $this->fillableParams($params);

        // Legacy `createMultiple()` defaults.
        if (empty($fill['ssl_status'])) {
            $fill['ssl_status'] = 'awaiting_dns';
        }
        $fill['network_status'] = 'validating';
        // `domains.state` has no DB-level default (unlike campaigns/
        // streams) — found live via tests-contract/: a freshly created
        // domain with no explicit `state` param silently got `state =
        // NULL`, invisible to every listing query afterward. Legacy
        // always creates as 'active'.
        $fill['state'] ??= 'active';

        $domain = new Domain();
        $domain->fill($fill);

        try {
            $domain->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        // isCreateAllowed() above already guarantees a non-null current user.
        $this->aclService->addAuthorPermission($this->currentUserService->get(), $domain);

        // Legacy `createMultiple()` can return several domains (one per
        // comma-separated name) — collapsed to a single domain here per the
        // "no domains feature" TODO above, so a single serialized object is
        // returned instead of an array (matches the reduced single-domain
        // scope, not the raw legacy array response shape).
        return response()->json($this->serializeDomain($domain));
    }

    public function updateAction(Request $request): Response
    {
        $id = $this->param($request, 'id');

        if (empty($id)) {
            return $this->notFound('Domain not found');
        }

        $domain = Domain::find((int) $id);

        if (! $domain) {
            return $this->notFound('Domain not found');
        }

        if (! $this->aclService->isEditAllowed($this->currentUserService->get(), $domain)) {
            return $this->forbidden('You are not allowed to edit this domain');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateDomainParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        // Legacy `_findOldParams()` translation.
        $params = $this->translateLegacyFields($params);

        // Legacy: `network_status`/`is_ssl` are never editable through this
        // action (real check/SSL-issuance flow only, via DomainCheckerService).
        unset($params['network_status'], $params['is_ssl']);

        // Legacy: `name` cannot change once the domain is active
        // (`Domain::isActive()` == `network_status === "active"`, NOT the
        // generic `state` column — silently dropped from the update data).
        if ($domain->network_status === 'active') {
            unset($params['name']);
        }

        $fill = $this->fillableParams($params);
        $domain->fill($fill);

        try {
            $domain->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        return response()->json($this->serializeDomain($domain));
    }

    public function listAsOptionsAction(Request $request): array
    {
        $rawAddDefault = $this->param($request, 'add_default');
        $addDefault = $rawAddDefault === null ? true : $this->boolParam($rawAddDefault);

        // Legacy `allActiveAndChecked()`: network_status == active AND
        // state == active.
        $domains = Domain::query()
            ->where('network_status', 'active')
            ->where('state', 'active')
            ->orderBy('id')
            ->get();

        $domains = $this->aclService->filterByAcl($domains, false, $this->currentUserService->get());

        // TODO: `DomainService::urlWithBasePath()` (full scheme://host/path
        // URL, via UrlService's base-path resolution) not ported — no
        // request-base-path infra in this project yet. `name` is used
        // directly as a simplified stand-in for the legacy `name` (URL)
        // field.
        $items = [];
        foreach ($domains as $domain) {
            $items[] = ['value' => $domain->id, 'name' => $domain->name];
        }

        if ($addDefault) {
            // TODO: LocaleService::t("domains.default_domain") not ported
            // (no i18n module) — plain English fallback string used here.
            array_unshift($items, ['value' => null, 'name' => 'Default domain']);
        }

        return $items;
    }

    // ---------------------------------------------------------------
    // Field translation (legacy `DomainsController::_findOldParams()`)
    // ---------------------------------------------------------------

    private function translateLegacyFields(array $params): array
    {
        if (! empty($params['redirect'])) {
            $params['ssl_redirect'] = $params['redirect'] === 'https';
            unset($params['redirect']);
        }

        if (! empty($params['is_robots_allowed'])) {
            $params['allow_indexing'] = $params['is_robots_allowed'];
            unset($params['is_robots_allowed']);
        }

        return $params;
    }

    // ---------------------------------------------------------------
    // Validation (§6: ValidationError -> 406 {field: ["message"]})
    // ---------------------------------------------------------------

    /**
     * Minimal port of `DomainValidator`: only `required`/`lengthMax(255)`
     * on `name` are replicated (same scope decision as
     * OffersController::validateOfferParams() /
     * LandingsController::validateLandingParams() /
     * TrafficSourcesController::validateTrafficSourceParams()). NOT ported
     * (TODO): uniqueness(name) (the `domains.name` column does carry a DB
     * UNIQUE constraint though — a duplicate name still fails, just as a
     * 500 dbError instead of a 406 validationError), required(created_at)/
     * required(updated_at) (always populated by Eloquent timestamps here,
     * so trivially satisfied and not worth replicating).
     */
    private function validateDomainParams(array $params, bool $partial = false): array
    {
        $errors = [];

        $present = array_key_exists('name', $params);
        $empty = $present && trim((string) $params['name']) === '';

        if ((! $partial && (! $present || $empty)) || ($partial && $present && $empty)) {
            $errors['name'] = ['The name field is required.'];
        } elseif ($present && ! $empty && mb_strlen((string) $params['name']) > 255) {
            $errors['name'] = ['The name field must not be greater than 255 characters.'];
        }

        return $errors;
    }

    private function fillableParams(array $params): array
    {
        return array_intersect_key($params, array_flip((new Domain())->getFillable()));
    }
}
