<?php

use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Auth compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=auth.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and the
| App\Http\Controllers\Admin\AuthController being ported in parallel)
| through Laravel's internal HTTP testing helpers (getJson/postJson) — no
| external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
| §4 (Auth) and §6 (response/error format).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
| NOTE: as of writing this file, `auth` is not yet registered in
| ObjectDispatchController::CONTROLLERS and AuthController does not exist
| — that work is being done in parallel by another agent. Every test below
| is written against the DOCUMENTED contract (§4) and is expected to fail
| with a plain 404 ("Not Found" from the dispatcher) until that lands; this
| is a known, expected, non-blocking state, not a bug in these tests.
|
| Password note: UserFactory (database/factories/UserFactory.php) hashes
| the literal string 'password' by default via Hash::make('password')
| unless a test explicitly overrides it — every "valid password" login
| below relies on that default.
|
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function authEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "auth.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

it('logs in with a valid login/password, returns {"success": true} and sets the states cookie', function () {
    UserFactory::new()->create(['login' => 'valid-login-user']);

    $response = $this->postJson(authEndpoint('login'), [
        'login' => 'valid-login-user',
        'password' => 'password',
    ]);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray();
    expect($data['success'] ?? null)->toBeTrue();

    // §4.1: cookie name is `states` (AuthService::COOKIE_PARAM). Only
    // assert presence, not its (possibly-encrypted) value — Laravel's
    // assertCookie() with no $value argument does not attempt to decrypt,
    // so this holds regardless of whether AuthController excludes `states`
    // from the EncryptCookies middleware.
    $response->assertCookie('states');
});

it('returns HTTP 200 (not 401/403) with a message for a wrong password — brute-force response shape', function () {
    // §4.2: BruteForceDetectionService / a plain wrong-password rejection
    // both surface as HTTP 200 with a `message` key, NOT a 4xx status —
    // the frontend distinguishes failure only by body shape.
    UserFactory::new()->create(['login' => 'wrong-password-user']);

    $response = $this->postJson(authEndpoint('login'), [
        'login' => 'wrong-password-user',
        'password' => 'definitely-not-the-password',
    ]);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray();
    expect($data)->toHaveKey('message');
    expect($data['success'] ?? null)->not->toBeTrue();
});

it('returns HTTP 200 with a message for a login that does not exist', function () {
    $response = $this->postJson(authEndpoint('login'), [
        'login' => 'no-such-user-at-all',
        'password' => 'whatever',
    ]);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray();
    expect($data)->toHaveKey('message');
    expect($data['success'] ?? null)->not->toBeTrue();
});

it('logs out and clears the states cookie', function () {
    UserFactory::new()->create(['login' => 'logout-user']);

    $login = $this->postJson(authEndpoint('login'), [
        'login' => 'logout-user',
        'password' => 'password',
    ]);
    $login->assertStatus(200);

    $response = $this->postJson(authEndpoint('logout'));

    // §4.1: logout clears the cookie (CookiesService::unsetCookie()) and
    // redirects to `?return=...`. We only assert on the cookie here — the
    // redirect target is an internal detail, not part of the documented
    // JSON contract.
    $response->assertCookieExpired('states');
});
