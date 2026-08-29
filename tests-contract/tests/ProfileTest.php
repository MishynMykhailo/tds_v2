<?php

/*
|--------------------------------------------------------------------------
| Profile contract tests
|--------------------------------------------------------------------------
|
| Locks down the `profile` module contract documented in
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md
| (`ProfileController`), run against the backend named by TDS_TEST_TARGET
| (see tests/Support/ApiClient.php).
|
| IMPORTANT: the doc lists `ProfileController`'s own-profile action as
| `show` (there is no `isAdmin` check on this controller - it always
| resolves to the currently logged-in user, no `id` param needed/accepted).
| Verified live: `profile.index` does NOT exist - it 404s with
| "Controller action \"indexAction\" is not defined" (`profile.show` is the
| real action). This file exercises `profile.show` for the round-trip and
| separately locks down the `profile.index` 404 so a Laravel port that adds
| an `index` action (or a caller that assumes one exists) shows up as a
| diff here.
|
*/

use Tests\Support\ApiClient;

beforeEach(function () {
    $this->api = new ApiClient();
    $loginResponse = $this->api->login();

    expect($loginResponse->getStatusCode())->toBe(200);
    $loginBody = ApiClient::json($loginResponse);
    expect($loginBody)->toBeArray()->and($loginBody['success'] ?? null)->toBeTrue();
});

describe('profile.show', function () {
    test('returns the currently logged-in user - no id param required or accepted', function () {
        $response = $this->api->get('profile.show');
        expect($response->getStatusCode())->toBe(200);

        $profile = ApiClient::json($response);
        expect($profile)->toBeArray();

        // The fixture login/pass in ApiClient::DEFAULT_LOGIN is the account
        // this suite logs in as for every test - profile.show must reflect
        // exactly that account, with no id param passed at all.
        expect($profile['login'])->toBe(ApiClient::DEFAULT_LOGIN);
        expect($profile)->toHaveKeys(['id', 'login', 'type', 'access_data', 'preferences']);

        // SECURITY: same leakage check as UsersTest.php - the logged-in
        // user's own profile must never echo back a password or its hash.
        foreach (['password', 'password_hash', 'passwordHash'] as $field) {
            expect($profile)->not->toHaveKey($field);
        }
    });

    test('is unaffected by an id query param - it always resolves to the session user', function () {
        // ProfileController has no isAdmin gate and (verified live) ignores
        // any `id` passed on the query string - it resolves the profile from
        // the session, not from a lookup param, unlike UsersController::show.
        $response = $this->api->get('profile.show', ['id' => 999999999]);
        expect($response->getStatusCode())->toBe(200);

        $profile = ApiClient::json($response);
        expect($profile['login'])->toBe(ApiClient::DEFAULT_LOGIN);
    });
});

describe('profile.index', function () {
    test('does not exist on ProfileController - verified live', function () {
        $response = $this->api->get('profile.index');

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body['error'])->toContain('indexAction');
    });
});
