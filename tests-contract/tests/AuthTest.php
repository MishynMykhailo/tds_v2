<?php

/*
|--------------------------------------------------------------------------
| Auth contract tests (auth.login)
|--------------------------------------------------------------------------
|
| Locks down the `auth.login` contract documented in
| docs/legacy-reference/frontend/backend_api_reference.md §4, run against
| the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Login is not a CRUD entity — no Fixtures helper needed, just the known
| admin credentials already used everywhere else in this suite
| (ApiClient::DEFAULT_LOGIN/DEFAULT_PASSWORD, admin/TdsAdmin2026!).
|
| Deliberately does NOT use the `beforeEach` auto-login pattern from
| CampaignsTest.php/StreamsTest.php — auth.login itself, in both its
| success and failure shapes, is exactly what's under test here.
|
| Response shapes below were captured live against the legacy backend
| (TDS_TEST_TARGET=http://host.docker.internal:8090) on 2026-08-28 and are
| recorded here as the reference contract for the Laravel rewrite:
|
|   valid login    -> HTTP 200, body {"success":true},
|                      Set-Cookie: states=v1<JWT>;Max-Age=2678400;...
|   wrong password -> HTTP 200, body {"message":"Incorrect password"}
|   unknown login  -> HTTP 200, body {"message":"Incorrect password"}
|                      (same message as a wrong password - does not leak
|                      whether the login exists, see §4.2)
|
*/

use Tests\Support\ApiClient;

describe('auth.login: valid credentials', function () {
    test('returns HTTP 200, {"success": true}, and sets the states cookie', function () {
        $api = new ApiClient();

        $response = $api->login();

        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body['success'] ?? null)->toBeTrue();

        // §4.1: cookie name is `states` (AuthService::COOKIE_PARAM), value
        // is a "v1" + JWT token, maxAge = 2678400s (31 days).
        $setCookieHeaders = $response->getHeader('Set-Cookie');
        expect($setCookieHeaders)->not->toBeEmpty();

        $statesCookie = null;
        foreach ($setCookieHeaders as $header) {
            if (str_starts_with($header, 'states=')) {
                $statesCookie = $header;
            }
        }
        expect($statesCookie)->not->toBeNull();
        expect($statesCookie)->toContain('states=v1');
        expect($statesCookie)->toContain('Max-Age=2678400');
    });
});

describe('auth.login: invalid credentials', function () {
    test('a wrong password for a real login returns HTTP 200 (not 401/403) with a message', function () {
        // §4.2: BruteForceDetectionService / plain wrong-password rejection
        // both surface as HTTP 200 with a `message` key, never a 4xx status
        // - the frontend distinguishes failure only by body shape.
        $api = new ApiClient();

        $response = $api->login(ApiClient::DEFAULT_LOGIN, 'definitely-the-wrong-password');

        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('message');
        expect($body['success'] ?? null)->not->toBeTrue();

        // No `states` cookie should be set on a failed login.
        $setCookieHeaders = $response->getHeader('Set-Cookie');
        foreach ($setCookieHeaders as $header) {
            expect($header)->not->toContain('states=');
        }
    });

    test('a login that does not exist returns HTTP 200 with the same generic message (no user-existence leak)', function () {
        $api = new ApiClient();

        $response = $api->login('no-such-login-'.bin2hex(random_bytes(4)), 'whatever-password');

        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('message');
        expect($body['success'] ?? null)->not->toBeTrue();
    });
});
