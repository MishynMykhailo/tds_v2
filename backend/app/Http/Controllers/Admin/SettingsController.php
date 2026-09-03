<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Settings\Controller\SettingsController` +
 * `Traffic\Settings\Repository\SettingsRepository::allAsHash()` +
 * `Traffic\Service\SettingsService::updateValues()`/`_updateInDb()` (old
 * codebase: application/Component/Settings/Controller/SettingsController.php,
 * application/Traffic/Settings/Repository/SettingsRepository.php,
 * application/Traffic/Service/SettingsService.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.12_settings.md
 * (cross-checked against the real source, not just the doc, per task
 * instructions — the doc matched what's actually in Initializer.php/
 * SettingsController.php here).
 *
 * Only `index` and `update` are ported here, per task scope — the other
 * legacy actions all depend on modules not yet ported anywhere in this
 * codebase:
 *  - `config` — `JsConfigService::get()`; the doc itself flags this as
 *    "outside the ?object= API" (a bootstrap config blob injected into the
 *    admin HTML page on first load), not really part of this JSON surface.
 *  - `find` — legacy reads through `CachedSettingsRepository` (a caching
 *    wrapper around SettingsRepository), not ported.
 *  - `getAuxiliaryData` — aggregates `CacheFactory::getAvailableStorages()`,
 *    `DelayedCommandsStorageRepository`, `RedisStorageService`,
 *    `AVCheckerService`, `ParameterRepository::getAvailableParameters()` —
 *    none of these modules exist in this port yet.
 *  - `changeLanguage` — legacy does a raw redirect (`$this->redirect("?")`),
 *    not a JSON response; also a *global* (not per-user) language switch,
 *    distinct from UserPreferencesController's per-user prefs.
 *
 * Legacy `Traffic\Service\ConfigService::isDemo()` gate on `update` is not
 * ported either — no ConfigService/"demo mode" concept exists anywhere in
 * this codebase yet (grepped app/ for isDemo() — no port found).
 *
 * Values are stored/returned as raw strings straight from the `value`
 * column (e.g. "1"/"0" for booleans, numbers-as-strings) — deliberately NOT
 * type-coerced here, matching every other already-ported controller's
 * "raw as stored" convention and the task's explicit instruction not to
 * guess types.
 */
class SettingsController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention established by CampaignsController/OffersController/etc.
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

    /**
     * Legacy `getParam($name)` — query first, then parsed body.
     *
     * Deliberately reads via `$request->query->all()` rather than
     * `$request->query->get($name)`: Symfony's `InputBag::get()` throws a
     * `BadRequestException` ("contains a non-scalar value") for an array
     * value (e.g. `only[]=a&only[]=b`), which legacy's `only` filter
     * explicitly supports (`is_array($only) ? $only : [$only]`) — using
     * `all()` instead avoids that 400, for both scalar and array params.
     */
    private function param(Request $request, string $name, $default = null)
    {
        $query = $request->query->all();
        if (array_key_exists($name, $query)) {
            return $query[$name];
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

    /** `Core\Exceptions\DenyError` shape: 403, {"error": "..."}. */
    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Repository-equivalent helper: `SettingsRepository::allAsHash($only)`.
    // ---------------------------------------------------------------

    /**
     * @param  array<int, string>|null  $only
     * @return array<string, mixed>
     */
    private function allAsHash(?array $only = null): array
    {
        $query = Setting::query();

        if ($only !== null && $only !== []) {
            $query->whereIn('key', $only);
        }

        $hash = [];
        foreach ($query->get(['key', 'value']) as $setting) {
            $hash[$setting->key] = $setting->value;
        }

        return $hash;
    }

    /**
     * Normalizes the `only` request param the same way legacy does inline
     * in `indexAction()`: `is_array($only) ? $only : [$only]` — a single
     * scalar becomes a one-element array, an array passes through as-is.
     * `null`/missing stays `null` (meaning "no filter").
     */
    private function normalizeOnly($only): ?array
    {
        if ($only === null) {
            return null;
        }

        return is_array($only) ? array_values($only) : [$only];
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user || ! $user->isAdmin()) {
            return $this->forbidden();
        }

        $only = $this->normalizeOnly($this->param($request, 'only'));

        return response()->json($this->allAsHash($only));
    }

    /**
     * Legacy `findAction()` — `{"key": ..., "value":
     * CachedSettingsRepository::get($key)}`, `value: null` for an
     * unknown/missing key (no default applied). No `isAdmin()` gate in
     * legacy (unlike `indexAction()`) — kept literal, not invented.
     * Found missing live (2026-09-03, tests-contract/ finally running
     * against this backend) — see docs/PORTING_LOG.md.
     */
    public function findAction(Request $request): Response
    {
        $key = (string) $this->param($request, 'key');

        return response()->json([
            'key' => $key,
            'value' => Setting::query()->find($key)?->value,
        ]);
    }

    /**
     * Legacy has no `isAdmin()` gate on `updateAction()` itself (only the
     * demo-mode check, not ported, and the `isPost()` check below) — the
     * real gate is the controller-level ACL check in
     * `AdminRequestFactory::checkAuthorization()` (`AclService::
     * isResourceAllowed($user, "settings")`), which happens before any
     * action runs and isn't ported per-action anywhere in this codebase.
     * Kept literal to the actual `updateAction()` source rather than
     * inventing an isAdmin() check that isn't really there.
     */
    public function updateAction(Request $request): Response
    {
        if (! $this->isPost($request)) {
            // Legacy throws a generic `Core\Application\Exception\Error`
            // here, which is NOT one of the specially-handled exception
            // types (§6) — it falls through to the catch-all
            // `CommonErrorHandler::handleAny()` branch: HTTP 500, HTML
            // body, not JSON. Replicated as a plain-text 500 (rather than
            // a JSON envelope) to keep that "not JSON" shape distinction
            // faithful, without pulling in the full HTML error-page
            // machinery for this edge case.
            return response('Must be post request', 500);
        }

        $newSettings = $this->postParams($request);

        foreach ($newSettings as $key => $value) {
            Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        return response()->json($this->allAsHash(array_keys($newSettings)));
    }
}
