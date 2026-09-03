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
 * `Component\Users\Controller\UsersController` +
 * `Component\Users\Serializer\UserSerializer`/`DecoratedUserSerializer` +
 * `Component\Users\Service\UserService` (old codebase:
 * application/Component/Users/Controller/UsersController.php,
 * application/Component/Users/Serializer/UserSerializer.php,
 * application/Component/Users/Serializer/DecoratedUserSerializer.php,
 * application/Component/Users/Service/UserService.php,
 * application/Component/Users/Validator/UserValidator.php,
 * application/Component/Users/Repository/UserRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
 *
 * Only a subset of the legacy action list is implemented (index, show,
 * create, update) — see per-method TODOs for what still depends on
 * modules not yet ported: `delete` (FeatureService-gated "can't delete last
 * admin" + cascading token invalidation), `setAccessData` (per-user ACL
 * rule editing UI, `AclService::saveAcl()` — a materially different write
 * shape from anything AclService (this port) currently exposes), the
 * `hasUsersFeature()`/users-count-limit gates (FeatureService not ported),
 * and `updateAndReAuthorize()`'s "re-sign the caller's own JWT after they
 * edit themselves" step (no cookie-issuing plumbing in this controller
 * layer — see AuthService::login() which is the only place that happens).
 *
 * ACL: gated purely on `$user->isAdmin()`, matching legacy exactly — see
 * AclService's class docblock for why `App\Models\User` is deliberately
 * NOT in `AclService::ACL_KEYS` (legacy has no per-user entity-level ACL
 * concept at all, only the admin/non-admin gate replicated here).
 */
class UsersController extends Controller
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

    private function postParam(Request $request, string $name, $default = null)
    {
        $body = $this->parsedBody($request);

        return is_array($body) && array_key_exists($name, $body) ? $body[$name] : $default;
    }

    private function postParams(Request $request): array
    {
        return $this->parsedBody($request) ?? [];
    }

    private function isPost(Request $request): bool
    {
        return ! empty($this->parsedBody($request)) || $request->isMethod('post');
    }

    // ---------------------------------------------------------------
    // §6 error-shape helpers
    // ---------------------------------------------------------------

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

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
    // Serialization (§8). Deliberately built on `$user->toArray()`
    // (which applies `User::$hidden` = ['password', 'password_hash'])
    // rather than the `refresh()->getAttributes()` pattern every other
    // ported controller uses — `getAttributes()` bypasses `$hidden`
    // entirely and would leak the bcrypt hash straight into the API
    // response. See task instructions / User model docblock.
    // ---------------------------------------------------------------

    private function serializeUser(User $user): array
    {
        $user->refresh();

        $data = $user->toArray();

        // UserSerializer::extra() — access_data (per-user ACL rule map) is
        // NOT ported: it depends on a `AclService::getByUserId()`-shaped
        // read (legacy `AclRuleRepository::all()` -> flattened
        // "<entity_type>_access_type"/"_selected_groups"/"_selected_entities"
        // map) that this port's AclService does not expose in that shape.
        // Left as an explicit empty-object stub, same convention as
        // OffersController's `group`/`affiliate_network`/`preview` TODO
        // stubs for not-yet-ported relations.
        $data['access_data'] = new \stdClass;

        $data['keyCount'] = ApiKey::query()->where('user_id', $user->id)->count();

        // UserPreferenceRepository::getPreferencesAsMap() defaults.
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

    public function indexAction(Request $request): Response
    {
        $currentUser = $this->currentUserService->get();
        if (! $currentUser || ! $currentUser->isAdmin()) {
            return $this->forbidden('You are not allowed to manage users');
        }

        // TODO: legacy also gates this on `FeatureService::hasUsersFeature()`
        // (throws PaymentRequired) — FeatureService not ported.
        $users = User::query()->orderBy('login')->get();

        return response()->json($users->map(fn (User $u) => $this->serializeUser($u))->values());
    }

    public function showAction(Request $request): Response
    {
        $currentUser = $this->currentUserService->get();
        if (! $currentUser || ! $currentUser->isAdmin()) {
            return $this->forbidden('You are not allowed to manage users');
        }

        $id = $this->param($request, 'id');
        $user = ! empty($id) ? User::find((int) $id) : null;

        if (! $user) {
            return $this->notFound('User not found');
        }

        return response()->json($this->serializeUser($user));
    }

    public function createAction(Request $request): Response
    {
        $currentUser = $this->currentUserService->get();
        if (! $currentUser || ! $currentUser->isAdmin()) {
            return $this->forbidden('You are not allowed to manage users');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateUserParams($params);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        unset($fill['password_hash'], $fill['password']);

        $user = new User;
        $user->fill($fill);
        // UserService::createUser(): `new_password`/`new_password_confirmation`
        // stand in for the legacy `password_hash` required-field validation
        // (see validateUserParams()) — Hash::make() here, never a raw copy
        // of the incoming password.
        $user->password_hash = Hash::make($params['new_password']);

        try {
            $user->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        if (isset($params['preferences']) && is_array($params['preferences'])) {
            $this->savePreferences($user, $params['preferences']);
        }

        // TODO: legacy `AclService::addDefaultAccess($user)` for non-admin
        // users (grants the "default for new users" resource set) — not
        // ported, no AclResourceRepository::getDefaultForNewUsers()
        // equivalent exists in this port's AclService yet.

        return response()->json($this->serializeUser($user));
    }

    public function updateAction(Request $request): Response
    {
        $currentUser = $this->currentUserService->get();
        if (! $currentUser || ! $currentUser->isAdmin()) {
            return $this->forbidden('You are not allowed to manage users');
        }

        $id = $this->param($request, 'id');
        $user = ! empty($id) ? User::find((int) $id) : null;

        if (! $user) {
            return $this->notFound('User not found');
        }

        if (! $this->isPost($request)) {
            return response()->json(null);
        }

        $params = $this->postParams($request);
        $errors = $this->validateUserParams($params, partial: true);
        if (! empty($errors)) {
            return $this->validationError($errors);
        }

        $fill = $this->fillableParams($params);
        unset($fill['password_hash'], $fill['password']);

        $user->fill($fill);

        // TODO: legacy `updateAndReAuthorize()` requires `current_password`
        // when an admin edits THEIR OWN record via this endpoint (as
        // opposed to editing another user), then re-signs that user's JWT.
        // Not ported here — see class docblock. Self password changes
        // belong to ProfileController::updateAction (which DOES verify
        // current_password), matching how the task split these two
        // controllers' responsibilities.
        if (! empty($params['new_password'])) {
            if (empty($params['new_password_confirmation'])
                || $params['new_password_confirmation'] !== $params['new_password']) {
                return $this->validationError(['new_password_confirmation' => ['Passwords do not match.']]);
            }
            $user->password_hash = Hash::make($params['new_password']);
        }

        try {
            $user->save();
        } catch (QueryException $e) {
            return $this->dbError($e);
        }

        if (isset($params['preferences']) && is_array($params['preferences'])) {
            $this->savePreferences($user, $params['preferences']);
        }

        return response()->json($this->serializeUser($user));
    }

    // ---------------------------------------------------------------
    // Validation (§6: ValidationError -> 406 {field: ["message"]})
    // ---------------------------------------------------------------

    /**
     * Minimal port of `UserValidator`: `login` (required, lengthMax 50),
     * `type` (required, in [ADMIN, USER]). `password_hash` is required in
     * the legacy validator too — replicated here as `new_password` being
     * required on create (the only way this port ever produces a
     * `password_hash`, see createAction()). NOT ported (TODO):
     * uniqueness(login) — `users.login` does carry a DB UNIQUE constraint
     * though, so a duplicate login still fails, just as a 500 dbError
     * instead of a 406 validationError (same documented trade-off as
     * DomainsController::validateDomainParams() for `domains.name`).
     */
    private function validateUserParams(array $params, bool $partial = false): array
    {
        $errors = [];

        $loginPresent = array_key_exists('login', $params);
        $loginEmpty = $loginPresent && trim((string) $params['login']) === '';

        if ((! $partial && (! $loginPresent || $loginEmpty)) || ($partial && $loginPresent && $loginEmpty)) {
            $errors['login'] = ['The login field is required.'];
        } elseif ($loginPresent && ! $loginEmpty && mb_strlen((string) $params['login']) > 50) {
            $errors['login'] = ['The login field must not be greater than 50 characters.'];
        }

        $typePresent = array_key_exists('type', $params);
        if (! $partial && ! $typePresent) {
            $errors['type'] = ['The type field is required.'];
        } elseif ($typePresent && ! in_array($params['type'], ['ADMIN', 'USER'], true)) {
            $errors['type'] = ['The selected type is invalid.'];
        }

        if (! $partial && empty($params['new_password'])) {
            $errors['new_password'] = ['The new password field is required.'];
        }
        if (! empty($params['new_password'])
            && (empty($params['new_password_confirmation'])
                || $params['new_password_confirmation'] !== $params['new_password'])) {
            $errors['new_password_confirmation'] = ['Passwords do not match.'];
        }

        return $errors;
    }

    private function fillableParams(array $params): array
    {
        return array_intersect_key($params, array_flip((new User)->getFillable()));
    }

    private function savePreferences(User $user, array $preferences): void
    {
        foreach ($preferences as $name => $value) {
            UserPreference::query()->updateOrCreate(
                ['user_id' => $user->id, 'pref_name' => $name],
                ['pref_value' => $value]
            );
        }
    }
}
