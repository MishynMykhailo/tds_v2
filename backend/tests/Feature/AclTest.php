<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| ACL enforcement tests (campaigns.* as the representative endpoint)
|--------------------------------------------------------------------------
|
| Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
| §5 (ACL) and §6 (403 -> DenyError shape).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
| Auth strategy: App\Http\Middleware\LegacyAuthMiddleware runs on every
| /admin/index.php request and UNCONDITIONALLY overwrites
| App\Services\CurrentUserService from the `states` cookie
| (`$this->currentUserService->set($this->authService->verifyFromCookie($token))`)
| — so a plain `app(CurrentUserService::class)->set($user)` called before
| the request gets clobbered back to null by the time the controller runs,
| since a test request normally carries no cookie at all.
|
| Driving a REAL cookie login round-trip (auth.login -> capture Set-Cookie
| -> ->withCookie('states', ...) -> next request) was tried first and
| doesn't currently work as a way to authenticate ACL tests, for two
| independent reasons hit while probing this file:
|   1. AuthService::verifyFromCookie() re-derives the user via
|      `whereRaw("MD5(CONCAT(login, '-tds')) = ?", ...)` — MySQL-only SQL.
|      SQLite (this suite's DB_CONNECTION, see .env.testing) has neither
|      MD5() nor CONCAT() and throws `no such function: MD5` when this
|      runs (confirmed by calling AuthService::verifyFromCookie() directly
|      with a real, freshly-issued token — see the diagnostic run in this
|      task's chat history for the raw QueryException).
|   2. Independently, a token handed to Laravel's test client via
|      ->withCookie('states', $token) does not arrive on the follow-up
|      request at all (`$request->cookie('states')` reads back null) -
|      root cause not pinned down (not this file's job to fix), but the
|      net effect is the same: no way to reach the controller as an
|      authenticated user through the real cookie path today.
| Both are pre-existing issues in AuthService/LegacyAuthMiddleware (owned
| by the parallel auth-track agent), not something to fix from this test
| file — flag them in the task report instead.
|
| Given that, ACL is exercised here by mocking App\Services\AuthService so
| LegacyAuthMiddleware resolves the desired test user without touching the
| JWT/DB verification path at all — this isolates "does AclService get
| enforced by the controller" (this file's actual subject) from "does the
| cookie/JWT plumbing work" (AuthTest.php's subject, and the two bugs
| above). Revisit this indirection once both upstream issues are fixed and
| a real cookie round-trip is viable.
*/

/** Build the legacy dispatcher URL for a given `object=campaigns.<action>`. */
function aclCampaignsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "campaigns.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Makes LegacyAuthMiddleware resolve $user as the current user on the next
 * request, regardless of the (absent) `states` cookie — see file docblock
 * for why this bypasses AuthService's real cookie verification instead of
 * driving it end to end.
 */
function actingAsForAcl(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('denies campaigns.create (403) for a USER with no ACL rule at all', function () {
    $user = UserFactory::new()->create();
    actingAsForAcl($user);

    $response = $this->postJson(aclCampaignsEndpoint('create'), [
        'name' => 'Should Be Denied',
        'alias' => 'acl-denied-create',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('campaigns', [
        'alias' => 'acl-denied-create',
    ]);
});

it('denies campaigns.show (403) for a USER with no ACL rule at all', function () {
    $campaign = CampaignFactory::new()->create();
    $user = UserFactory::new()->create();
    actingAsForAcl($user);

    $response = $this->getJson(aclCampaignsEndpoint('show', ['id' => $campaign->id]));

    $response->assertStatus(403);
});

it('allows campaigns.create (200) for an ADMIN user', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForAcl($admin);

    $response = $this->postJson(aclCampaignsEndpoint('create'), [
        'name' => 'Admin Can Create',
        'alias' => 'acl-admin-create',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('campaigns', [
        'alias' => 'acl-admin-create',
    ]);
});

it('allows campaigns.show (200) for an ADMIN user', function () {
    $campaign = CampaignFactory::new()->create();
    $admin = UserFactory::new()->admin()->create();
    actingAsForAcl($admin);

    $response = $this->getJson(aclCampaignsEndpoint('show', ['id' => $campaign->id]));

    $response->assertStatus(200);
    expect($response->json('id'))->toBe($campaign->id);
});
