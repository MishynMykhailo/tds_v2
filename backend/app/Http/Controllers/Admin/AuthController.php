<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuthService;
use App\Services\CurrentUserService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compat port of legacy `Component\Users\Controller\AuthController` (old
 * codebase: application/Component/Users/Controller/AuthController.php).
 * See docs/legacy-reference/frontend/backend_api_reference.md §4 (auth/
 * sessions) and §10.8.
 *
 * Legacy's `indexAction` server-renders an HTML login form
 * (`renderView(".../login.phtml")`) — not meaningful for a JSON API, so this
 * port returns `{"authenticated": bool}` instead (per task instructions).
 *
 * NOT ported: `BruteForceDetectionService` (IP ban after repeated failed
 * logins, §4.2) — out of scope for this task; only the response *shape* for
 * bad credentials (200 + `{"message": ...}`, never 401/403) is replicated.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading helpers (§7) — duplicated per-controller
    // convention established by CampaignsController/StreamsController.
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

    /** Legacy `getPostParam($name)` — parsed body only, no query fallback. */
    private function postParam(Request $request, string $name, $default = null)
    {
        $body = $this->parsedBody($request);

        return is_array($body) && array_key_exists($name, $body) ? $body[$name] : $default;
    }

    // ---------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------

    /**
     * Legacy renders the login HTML form here; JSON-API equivalent is just
     * "are you logged in" (per task instructions).
     */
    public function indexAction(Request $request): array
    {
        return ['authenticated' => $this->currentUserService->exists()];
    }

    /**
     * Legacy `getPostParam()` is body-only (no query fallback) — confirmed
     * against the real old source (`$this->getPostParam("login")` /
     * `"password"`), so `login`/`password` are read the same way here, not
     * via the query-first `param()` helper.
     *
     * Always HTTP 200: `{"success": true}` + `states` cookie on success,
     * `{"message": "..."}` on any failure — the frontend distinguishes by
     * payload shape, not status code (§4.2/§10.8).
     */
    public function loginAction(Request $request): array
    {
        $login = trim((string) $this->postParam($request, 'login', ''));
        $password = (string) $this->postParam($request, 'password', '');

        if ($login === '') {
            return ['message' => 'Please enter your login'];
        }

        if ($password === '') {
            return ['message' => 'Please enter your password'];
        }

        $token = $this->authService->login($login, $password);

        if ($token === null) {
            return ['message' => 'Incorrect login or password'];
        }

        Cookie::queue(
            AuthService::COOKIE_PARAM,
            $token,
            (int) (AuthService::TTL_SECONDS / 60), // CookieJar::make() takes minutes, legacy TTL is in seconds
            path: '/',
            domain: null,
            secure: false,
            httpOnly: false,
        );

        return ['success' => true];
    }

    public function logoutAction(Request $request): Response
    {
        Cookie::queue(Cookie::forget(AuthService::COOKIE_PARAM));

        return response('');
    }
}
