<?php

namespace App\Services;

use App\Models\User;

/**
 * Shared contract between the auth layer and every controller that needs
 * to know "who is making this request" (ACL checks, addAuthorPermission,
 * etc.) — mirrors the legacy `Component\Users\Service\CurrentUserService`
 * (see docs/legacy-reference/frontend/backend_api_reference.md §4.3): a
 * plain getter/setter, no session, set once per request by the auth
 * middleware and read by controllers/services further down the pipeline.
 *
 * Usage:
 *  - Auth middleware (App\Http\Middleware\*): CurrentUserService::set($user)
 *    after verifying the `states` cookie, or set(null) if unauthenticated.
 *  - Controllers/services: CurrentUserService::get() — returns null if the
 *    request is unauthenticated (caller decides whether that's an error).
 *
 * Bound as a singleton in the container so it's naturally scoped to one
 * request under php-fpm/artisan serve; NOTE: under Octane (long-lived
 * workers) this MUST be reset between requests to avoid leaking one user's
 * identity into the next request on the same worker — see the
 * Db::instance() bug class documented in docs/legacy-reference/BUG_PATTERNS.md
 * for exactly this kind of cross-request state leak. Whoever wires Octane
 * support later must call CurrentUserService::set(null) in a
 * request-lifecycle-ended hook.
 */
class CurrentUserService
{
    private ?User $user = null;

    public function set(?User $user): void
    {
        $this->user = $user;
    }

    public function get(): ?User
    {
        return $this->user;
    }

    public function exists(): bool
    {
        return $this->user !== null;
    }
}
