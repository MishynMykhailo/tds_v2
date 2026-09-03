<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Models\UserPreference;
use App\Services\CurrentUserService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Users\Controller\ProfileController` (old codebase:
 * application/Component/Users/Controller/ProfileController.php), reusing
 * `Component\Users\Serializer\UserSerializer` (same as UsersController)
 * and `Component\Users\Service\UserService::changePassword()`/
 * `Component\Users\Service\AuthService::isUserPasswordCorrect()`.
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.8_users_groups_acl.md:
 * "ProfileController — свой профиль (без isAdmin): currentAccess (свои
 * ACL-права), show, update (смена пароля с проверкой текущего + обновление
 * произвольных preferences одним вызовом), languages (статический
 * справочник ru/en), timezones."
 *
 * UNLIKE UsersController, this operates on `CurrentUserService::get()`
 * ONLY — there is no `id`/user-selection param anywhere in this
 * controller, by design (legacy: no `isAdmin()` gate at all, since a user
 * is always allowed to see/edit their OWN data).
 *
 * Only `index` (legacy `show`, renamed for this port's `object=profile`
 * dispatch convention — no other ported controller keeps a bare `show`
 * with no id param) and `update` are implemented — see per-method TODOs.
 * NOT ported: `currentAccess` (depends on the same
 * `AclService::getByUserId()`-shaped read UsersController's `access_data`
 * stub already documents as unavailable), `languages`/`timezones` (static
 * reference-data endpoints, out of scope for this task — no
 * TimezoneRepository equivalent exists in this port).
 */
class ProfileController extends Controller
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

    private function postParam(Request $request, string $name, $default = null)
    {
        $body = $this->parsedBody($request);

        return is_array($body) && array_key_exists($name, $body) ? $body[$name] : $default;
    }

    // ---------------------------------------------------------------
    // §6 error-shape helpers
    // ---------------------------------------------------------------

    private function validationError(array $errors): Response
    {
        return response()->json($errors, 406);
    }

    private function dbError(QueryException $e): Response
    {
        return response()->json(['error' => $e->getMessage(), 'stacktrace' => $e->getTraceAsString()], 500);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Serialization (§8) — same shape as UsersController::serializeUser(),
    // duplicated per this codebase's established per-controller
    // independence convention rather than shared via inheritance/a trait.
    // ---------------------------------------------------------------

    private function serializeUser(User $user): array
    {
        $user->refresh();

        $data = $user->toArray();

        $data['access_data'] = new \stdClass;

        $data['keyCount'] = ApiKey::query()->where('user_id', $user->id)->count();

        $preferences = UserPreference::query()
            ->where('user_id', $user->id)
            ->pluck('pref_value', 'pref_name')
            ->all();
        $preferences['timezone'] ??= 'UTC';
        $preferences['language'] ??= 'ru';
        $data['preferences'] = $preferences;

        return $data;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    /**
     * Legacy `Component\Users\Controller\ProfileController::showAction()`
     * — confirmed live (2026-09-03, tests-contract/): this was wrongly
     * named `indexAction` here, but `?object=profile.index` doesn't
     * exist in legacy at all (only `.show`/`.update`/`.currentAccess`/
     * `.languages`/`.timezones`) — `profile.index` 404s against the real
     * legacy backend. Renamed to match; see docs/PORTING_LOG.md.
     */
    public function showAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        return response()->json($this->serializeUser($user));
    }

    public function updateAction(Request $request): Response
    {
        $user = $this->currentUserService->get();
        if (! $user) {
            return $this->forbidden('You must be logged in');
        }

        $newPassword = $this->postParam($request, 'new_password');

        if (! empty($newPassword)) {
            $currentPassword = $this->postParam($request, 'current_password');

            // Legacy `AuthService::isUserPasswordCorrect()` — always
            // required before a password change, own-profile or not.
            if (empty($user->password_hash) || ! Hash::check((string) $currentPassword, $user->password_hash)) {
                return $this->validationError(['current_password' => ['Current password is incorrect.']]);
            }

            $newPasswordConfirmation = $this->postParam($request, 'new_password_confirmation');
            if ($newPasswordConfirmation !== $newPassword) {
                return $this->validationError(['new_password_confirmation' => ['Passwords do not match.']]);
            }

            $user->password_hash = Hash::make($newPassword);

            try {
                $user->save();
            } catch (QueryException $e) {
                return $this->dbError($e);
            }

            // TODO: legacy `UserService::changePassword()` also calls
            // `AuthService::expireAllTokens($user)` after a successful
            // change (drops every other active session) — this port's
            // AuthService has no token-revocation table/method yet
            // (`user_password_hashes` rows are only ever added, never
            // pruned here).
        }

        $preferences = $this->postParam($request, 'preferences');
        if (is_array($preferences)) {
            foreach ($preferences as $name => $value) {
                UserPreference::query()->updateOrCreate(
                    ['user_id' => $user->id, 'pref_name' => $name],
                    ['pref_value' => $value]
                );
            }
        }

        return response()->json($this->serializeUser($user));
    }
}
