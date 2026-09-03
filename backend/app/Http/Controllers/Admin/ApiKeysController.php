<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Users\Controller\ApiKeysController` +
 * `Component\Users\Serializer\ApiKeySerializer` +
 * `Component\Users\Service\ApiKeysService` +
 * `Component\Users\Repository\ApiKeysRepository` (old codebase:
 * application/Component/Users/Controller/ApiKeysController.php,
 * application/Component/Users/Serializer/ApiKeySerializer.php,
 * application/Component/Users/Service/ApiKeysService.php,
 * application/Component/Users/Repository/ApiKeysRepository.php).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.8_users_groups_acl.md:
 * "ApiKeysController — API-ключи для REST AdminApi: getAll (userId — только
 * для админа, смотреть чужие ключи), add (генерирует случайный ключ),
 * delete (keyId)."
 *
 * Action names match legacy exactly: getAll/add/delete (verified live,
 * 2026-09-03 — a prior version of this file renamed them to
 * index/create/remove on the false premise that "UsersController/
 * GroupsController already take this divergence"; reading the actual
 * legacy `Component\Users\Controller\UsersController`/`GroupsController`
 * shows those two controllers' legacy action names already ARE
 * index/create/show/update/delete, so there was no divergence to be
 * consistent with — `ObjectDispatchController::handle()` maps
 * `object=apiKeys.add` to the literal method `addAction`, with no alias
 * table, so any renamed action here is unreachable and 404s).
 *
 * ACL: no AclService entity-type involved (API keys aren't a
 * campaigns/offers/... style entity) — access is gated purely on
 * "is this MY key" vs. `?userId=` + `isAdmin()` for looking at someone
 * else's, exactly like legacy's `_findUser()`.
 */
class ApiKeysController extends Controller
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

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

    // ---------------------------------------------------------------
    // Legacy `ApiKeysController::_findUser()`: `?userId=` targets someone
    // else's keys and requires `isAdmin()`; otherwise falls back to the
    // current user. Returns a Response on any failure so callers can
    // `if ($result instanceof Response) return $result;`.
    // ---------------------------------------------------------------

    private function resolveUser(Request $request): User|Response
    {
        $userId = (int) $this->param($request, 'userId');
        $currentUser = $this->currentUserService->get();

        if ($userId) {
            if (! $currentUser || ! $currentUser->isAdmin()) {
                return $this->forbidden('You are not allowed to view other users\' API keys');
            }

            $user = User::find($userId);

            return $user ?? $this->notFound('User not found');
        }

        return $currentUser ?? $this->forbidden('You must be logged in');
    }

    // ---------------------------------------------------------------
    // Serialization (§8). ApiKeySerializer: id/key/datetime, datetime
    // formatted — legacy formats via the active locale
    // (`LocaleService::t("format.datetime")`); this port uses a plain
    // `toDateTimeString()` like every other ported controller's
    // created_at/updated_at handling (no locale-formatting layer exists
    // here).
    // ---------------------------------------------------------------

    private function serializeApiKey(ApiKey $key): array
    {
        $key->refresh();

        return [
            'id' => $key->id,
            'key' => $key->key,
            'datetime' => $key->datetime instanceof \DateTimeInterface
                ? Carbon::instance($key->datetime)->toDateTimeString()
                : $key->datetime,
        ];
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    public function getAllAction(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $keys = ApiKey::query()->where('user_id', $user->id)->orderBy('id')->get();

        return response()->json($keys->map(fn (ApiKey $k) => $this->serializeApiKey($k))->values());
    }

    public function addAction(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        // Legacy `ApiKeysService::_generateRandom()` = `md5(uniqid(rand(),
        // true) . SALT)` — a 32-char lowercase hex string. Reproduced here
        // with the same shape (32 lowercase hex chars) via a
        // cryptographically strong RNG instead of md5(uniqid()) (there is
        // no `SALT` constant in this port to mix in, and random_bytes() is
        // the stronger choice anyway).
        $key = ApiKey::create([
            'key' => bin2hex(random_bytes(16)),
            'user_id' => $user->id,
            'datetime' => now(),
        ]);

        return response()->json($this->serializeApiKey($key));
    }

    public function deleteAction(Request $request): ?Response
    {
        $user = $this->resolveUser($request);
        if ($user instanceof Response) {
            return $user;
        }

        $keyId = (int) $this->param($request, 'keyId');

        $key = ApiKey::query()->where('id', $keyId)->where('user_id', $user->id)->first();
        if (! $key) {
            return $this->notFound('Key '.$keyId.' not found');
        }

        $key->delete();

        return null;
    }
}
