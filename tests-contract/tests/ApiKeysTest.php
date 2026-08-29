<?php

/*
|--------------------------------------------------------------------------
| ApiKeys contract tests
|--------------------------------------------------------------------------
|
| Locks down the `apiKeys` module contract documented in
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md
| (`ApiKeysController`, `ApiKeySerializer`), run against the backend named
| by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| `apiKeys.add` takes no payload (verified live: it generates a random key
| server-side for the logged-in user, `keyId`/`userId` are only relevant to
| `delete`/`getAll`) - so unlike the other modules in this suite there is no
| Fixtures::createApiKey() helper; each test below calls `apiKeys.add`
| directly and cleans up via `apiKeys.delete` in the same test, since a key
| adds real, indefinitely-lived admin-API credentials to the live shared
| target and there is no natural "throwaway" scoping like a random alias.
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

describe('apiKeys.add / apiKeys.getAll', function () {
    test('a freshly added key has the documented {id,key,datetime} shape and appears in getAll', function () {
        $addResponse = $this->api->post('apiKeys.add');
        expect($addResponse->getStatusCode())->toBe(200);

        $added = ApiClient::json($addResponse);
        expect($added)->toBeArray();

        // §10.8: `ApiKeySerializer` only exposes id, key, datetime.
        expect($added)->toHaveKeys(['id', 'key', 'datetime']);
        expect($added['key'])->toBeString()->not->toBeEmpty();

        $keyId = $added['id'];

        try {
            $listResponse = $this->api->get('apiKeys.getAll');
            expect($listResponse->getStatusCode())->toBe(200);

            $keys = ApiClient::json($listResponse);
            expect($keys)->toBeArray()->not->toBeEmpty();

            foreach ($keys as $key) {
                expect($key)->toBeArray();
                expect($key)->toHaveKeys(['id', 'key', 'datetime']);
            }

            $matching = array_values(array_filter(
                $keys,
                static fn ($k) => (string) $k['id'] === (string) $keyId
            ));
            expect($matching)->not->toBeEmpty();
            expect($matching[0]['key'])->toBe($added['key']);
        } finally {
            // Clean up: this key grants real AdminApi (§3) access to the
            // shared live target, don't leave it lying around.
            $this->api->post('apiKeys.delete', [], ['keyId' => $keyId]);
        }
    });

    test('apiKeys.delete removes the key from a subsequent getAll', function () {
        $added = ApiClient::json($this->api->post('apiKeys.add'));
        $keyId = $added['id'];

        $deleteResponse = $this->api->post('apiKeys.delete', [], ['keyId' => $keyId]);
        expect($deleteResponse->getStatusCode())->toBe(200);

        $keys = ApiClient::json($this->api->get('apiKeys.getAll'));
        $matching = array_values(array_filter(
            $keys,
            static fn ($k) => (string) $k['id'] === (string) $keyId
        ));
        expect($matching)->toBeEmpty();
    });
});
