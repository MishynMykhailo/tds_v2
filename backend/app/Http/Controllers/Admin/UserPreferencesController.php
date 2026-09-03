<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserPreference;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Users\Controller\UserPreferencesController` +
 * `Component\Users\Serializer\UserPreferenceSerializer` +
 * `Component\Users\Service\UserPreferenceService` +
 * `Component\Users\Repository\UserPreferenceRepository` (old codebase:
 * application/Component/Users/Controller/UserPreferencesController.php,
 * application/Component/Users/Serializer/UserPreferenceSerializer.php,
 * application/Component/Users/Service/UserPreferenceService.php,
 * application/Component/Users/Repository/UserPreferenceRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.8_users_groups_acl.md:
 * "UserPreferencesController — key-value настройки пользователя: index, get
 * (pref_name), set (pref_name+pref_value)." Legacy action names
 * (index/get/set) are kept verbatim here (unlike Users/Groups/ApiKeys,
 * which diverge to a RESTish index/create/... naming) since they already
 * match this port's `<action>Action` convention 1:1.
 *
 * Always operates on `CurrentUserService::get()` — same "no id param,
 * always the caller's own data" shape as ProfileController, no isAdmin()
 * gate anywhere in the legacy contract.
 */
class UserPreferencesController extends Controller
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

    // ---------------------------------------------------------------
    // §6 error-shape helpers
    // ---------------------------------------------------------------

    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Serialization (§8). UserPreferenceSerializer::$_fields =
    // ["pref_name", "pref_value"] — deliberately NOT the raw model id.
    // ---------------------------------------------------------------

    private function serializePreference(UserPreference $preference): array
    {
        return [
            'pref_name' => $preference->pref_name,
            'pref_value' => $preference->pref_value,
        ];
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function indexAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $preferences = UserPreference::query()->where('user_id', $user->id)->get();

        return response()->json($preferences->map(fn (UserPreference $p) => $this->serializePreference($p))->values());
    }

    public function getAction(Request $request): Response|string|null
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $prefName = $this->param($request, 'pref_name');

        // Legacy `UserPreferenceRepository::get()` returns the raw scalar
        // `pref_value` directly (via `DataRepository::getOne()`), and
        // `ObjectDispatchController::handle()` passes any non-array/object
        // action result through as a plain string body (`response((string)
        // $result)`), NOT JSON-encoded — verified live against legacy port
        // 8090: a set value round-trips completely unquoted, and an unset
        // pref_name is HTTP 200 with an EMPTY body (not JSON `null`). A
        // prior version of this action wrapped the value in `json_encode()`
        // instead, which quoted strings and produced the literal text
        // `null` for a missing preference — both wrong against the real
        // contract (tests-contract's UserPreferencesTest asserts the raw,
        // unquoted body). Returning the bare scalar here lets
        // ObjectDispatchController's generic (string) cast do the right
        // thing: the string itself, or '' for a real `null`.
        return UserPreference::query()
            ->where('user_id', $user->id)
            ->where('pref_name', $prefName)
            ->value('pref_value');
    }

    public function setAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $prefName = $this->param($request, 'pref_name');
        $prefValue = $this->param($request, 'pref_value');

        // Minimal port of `UserPreferenceValidator`: `pref_name` required
        // (`user_id`/`pref_value` are always supplied by this action
        // itself — `pref_value` legacy-required too, but an intentionally
        // empty value, e.g. clearing a preference, is a legitimate use
        // case this port allows through, same "required means non-missing,
        // not non-falsy" reading CampaignsController/OffersController use
        // elsewhere for optional-looking fields).
        if (empty($prefName)) {
            return $this->validationError(['pref_name' => ['The pref_name field is required.']]);
        }

        $preference = UserPreference::query()->updateOrCreate(
            ['user_id' => $user->id, 'pref_name' => $prefName],
            ['pref_value' => $prefValue]
        );

        return response()->json($this->serializePreference($preference));
    }
}
