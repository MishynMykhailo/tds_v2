<?php

/*
|--------------------------------------------------------------------------
| Users contract tests
|--------------------------------------------------------------------------
|
| Locks down the `users` module contract documented in
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md
| (`UsersController`), run against the backend named by TDS_TEST_TARGET
| (see tests/Support/ApiClient.php).
|
| Every regression test below creates its OWN user fixture via
| `users.create` (see tests/Support/Fixtures.php) before reading/asserting
| anything - it never depends on a specific pre-existing row. The target
| database is live and mutable (shared with humans clicking around and
| other agents), so pinning assertions to a fixed id is fragile (see the
| equivalent note in CampaignsTest.php).
|
| These tests exercise CRUD as the already-logged-in admin only - they
| never log in as the created user, so the fixture's password is set but
| deliberately not exercised as a credential here (see Fixtures::createUser()).
|
| SECURITY: the most important assertion in this file is that neither
| `users.create` nor `users.show` ever echoes back a password or password
| hash - verified live against the legacy backend, see the dedicated test
| below.
|
*/

use Tests\Support\ApiClient;
use Tests\Support\Fixtures;

beforeEach(function () {
    $this->api = new ApiClient();
    $loginResponse = $this->api->login();

    expect($loginResponse->getStatusCode())->toBe(200);
    $loginBody = ApiClient::json($loginResponse);
    expect($loginBody)->toBeArray()->and($loginBody['success'] ?? null)->toBeTrue();
});

// Field names that must never appear anywhere in a users.* response body,
// no matter how they're spelled/cased. `password_hash` is the literal
// create-payload field name (see Fixtures::createUser()); `password` is the
// obvious alternative a careless serializer might leak under.
const USER_SECRET_FIELDS = ['password', 'password_hash', 'passwordHash'];

describe('users.create / users.show', function () {
    test('a freshly created user round-trips via show, and the response never leaks a password or hash', function () {
        // A known, explicit plaintext password (not the Fixtures default) so
        // this test can assert it never appears verbatim anywhere in the
        // response body, not just check for the absence of known field names.
        $rawPassword = 'CtRoundTrip!' . bin2hex(random_bytes(4));

        $created = Fixtures::createUser($this->api, ['password_hash' => $rawPassword]);
        $id = (int) $created['id'];

        expect($created)->toHaveKeys(['id', 'login', 'type']);
        foreach (USER_SECRET_FIELDS as $field) {
            expect($created)->not->toHaveKey($field);
        }
        expect((string) json_encode($created))->not->toContain($rawPassword);

        $response = $this->api->get('users.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $user = ApiClient::json($response);
        expect($user)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $user['id'])->toBe($id);
        expect($user['login'])->toBe($created['login']);
        expect($user['type'])->toBe('USER');

        // §10.8, SECURITY: verified live - UsersController's show/create
        // responses only ever contain
        // {id, login, type, rules, permissions, access_data, keyCount, preferences},
        // never the password or its hash.
        foreach (USER_SECRET_FIELDS as $field) {
            expect($user)->not->toHaveKey($field);
        }

        // Belt-and-braces: the plaintext password used to create the fixture
        // must not appear verbatim anywhere in the show response body either
        // (e.g. smuggled under an unexpected key name).
        $rawBody = (string) $response->getBody();
        expect($rawBody)->not->toContain($rawPassword);
    });

    test('users.create accepts type=ADMIN and never leaks a password or hash either', function () {
        $created = Fixtures::createUser($this->api, ['type' => 'ADMIN']);

        expect($created['type'])->toBe('ADMIN');
        foreach (USER_SECRET_FIELDS as $field) {
            expect($created)->not->toHaveKey($field);
        }
    });

    test('rejects a request with no id as a 404 NotFoundError with a stacktrace body', function () {
        // §10.8: verified live, an absent `id` defaults to 0 and 404s with
        // "User #0 not found" - same §6 NotFoundError shape as other modules.
        $response = $this->api->get('users.show');

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
        expect($body['error'])->toBeString()->not->toBeEmpty();
        expect($body['stacktrace'])->toBeString()->not->toBeEmpty();
    });

    test('rejects a request with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('users.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('users.index', function () {
    test('a freshly created user appears in the index, and no row anywhere leaks a password or hash', function () {
        $created = Fixtures::createUser($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('users.index');

        expect($response->getStatusCode())->toBe(200);

        $users = ApiClient::json($response);
        expect($users)->toBeArray()->not->toBeEmpty();

        foreach ($users as $user) {
            expect($user)->toBeArray();
            expect($user)->toHaveKeys(['id', 'login', 'type']);

            // SECURITY: not a single row in the index - not just the fixture
            // this test created - may leak a password or its hash.
            foreach (USER_SECRET_FIELDS as $field) {
                expect($user)->not->toHaveKey($field);
            }
        }

        $matching = array_values(array_filter(
            $users,
            static fn ($u) => (int) $u['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['login'])->toBe($created['login']);
        expect($matching[0]['type'])->toBe('USER');
    });
});

describe('users.listAsOptions', function () {
    test('does not exist on UsersController - verified live', function () {
        // §10.8 describes `UsersController` as exposing `index`, `create`/
        // `update`/`delete`, and `setAccessData` - it does NOT document a
        // `listAsOptions` action (unlike `GroupsController`, which does have
        // one - see GroupsTest.php). Verified live: unlike `groups`/
        // `trafficSources`/`campaigns`, calling `users.listAsOptions`
        // dispatches to `UsersController` and 404s because
        // `listAsOptionsAction` is not defined on it - the legacy backend
        // has no "options" endpoint for users. This test locks down that
        // absence so a Laravel port intentionally adding the action (or
        // accidentally routing it elsewhere) shows up as a diff here.
        $response = $this->api->get('users.listAsOptions');

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body['error'])->toContain('listAsOptionsAction');
    });
});
