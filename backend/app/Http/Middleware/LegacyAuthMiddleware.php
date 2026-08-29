<?php

namespace App\Http\Middleware;

use App\Services\AuthService;
use App\Services\CurrentUserService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-verifies the "states" cookie on every admin-panel request and populates
 * App\Services\CurrentUserService — mirrors legacy
 * `AdminContext::modifyRequest()` -> `AuthService::loadFromCookieToken()`
 * (see docs/legacy-reference/frontend/backend_api_reference.md §4.1: "on
 * EVERY HTTP request to the admin panel the cookie is decrypted again").
 *
 * This middleware NEVER blocks the request itself — ACL/"must be logged in"
 * enforcement (legacy `checkAuthorization()`, §2.1) is the responsibility of
 * the ACL layer wired into the controllers by a parallel effort. This
 * middleware's only job is: cookie in, CurrentUserService::set() out.
 */
class LegacyAuthMiddleware
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie(AuthService::COOKIE_PARAM);

        $user = $this->authService->verifyFromCookie($token);

        $this->currentUserService->set($user);

        return $next($request);
    }
}
