<?php

/*
|--------------------------------------------------------------------------
| UserPreferences contract tests
|--------------------------------------------------------------------------
|
| Locks down the `userPreferences` module contract documented in
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md
| (`UserPreferencesController`), run against the backend named by
| TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| IMPORTANT, verified live: unlike every other endpoint in this suite,
| `userPreferences.get`'s response body is the raw `pref_value` string
| itself - NOT a JSON-encoded value, and NOT wrapped in an object like
| `{"pref_name":...,"pref_value":...}` (that shape is only what `.set`
| echoes back). So `ApiClient::json()` must NOT be used on `.get` responses
| - json_decode() on a bare, unquoted string like `hello_value` fails.
| Read `(string) $response->getBody()` directly instead. A `.get` for a
| pref_name that was never set returns HTTP 200 with an EMPTY body (not a
| 404) - verified live.
|
| Every test below uses its own randomly-named `pref_name` (preferences are
| a live, shared, per-account key-value store - the admin account this
| suite logs in as is shared with humans/other agents, so reusing a
| well-known pref_name like "language" risks clobbering real UI state).
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

function randomPrefName(): string
{
    return 'ct_pref_' . bin2hex(random_bytes(4));
}

describe('userPreferences.set / userPreferences.get', function () {
    test('a value set via .set round-trips verbatim via .get as a raw (non-JSON) body', function () {
        $prefName = randomPrefName();
        $prefValue = 'contract-test-value-' . bin2hex(random_bytes(3));

        $setResponse = $this->api->post('userPreferences.set', [], [
            'pref_name' => $prefName,
            'pref_value' => $prefValue,
        ]);
        expect($setResponse->getStatusCode())->toBe(200);

        $setBody = ApiClient::json($setResponse);
        expect($setBody)->toBeArray();
        expect($setBody)->toBe(['pref_name' => $prefName, 'pref_value' => $prefValue]);

        $getResponse = $this->api->get('userPreferences.get', ['pref_name' => $prefName]);
        expect($getResponse->getStatusCode())->toBe(200);

        // Deliberately NOT ApiClient::json() - see file header.
        $rawValue = (string) $getResponse->getBody();
        expect($rawValue)->toBe($prefValue);
    });

    test('overwriting an existing pref_name replaces its value', function () {
        $prefName = randomPrefName();

        $this->api->post('userPreferences.set', [], ['pref_name' => $prefName, 'pref_value' => 'first']);
        $this->api->post('userPreferences.set', [], ['pref_name' => $prefName, 'pref_value' => 'second']);

        $getResponse = $this->api->get('userPreferences.get', ['pref_name' => $prefName]);
        expect((string) $getResponse->getBody())->toBe('second');
    });

    test('.get for a pref_name that was never set returns HTTP 200 with an empty body, not a 404', function () {
        $response = $this->api->get('userPreferences.get', ['pref_name' => randomPrefName()]);

        expect($response->getStatusCode())->toBe(200);
        expect((string) $response->getBody())->toBe('');
    });
});

describe('userPreferences.index', function () {
    test('a freshly set preference appears in the index as a {pref_name,pref_value} row', function () {
        $prefName = randomPrefName();
        $prefValue = 'index-check-' . bin2hex(random_bytes(3));

        $this->api->post('userPreferences.set', [], [
            'pref_name' => $prefName,
            'pref_value' => $prefValue,
        ]);

        $response = $this->api->get('userPreferences.index');
        expect($response->getStatusCode())->toBe(200);

        $prefs = ApiClient::json($response);
        expect($prefs)->toBeArray()->not->toBeEmpty();

        foreach ($prefs as $pref) {
            expect($pref)->toBeArray();
            expect($pref)->toHaveKeys(['pref_name', 'pref_value']);
        }

        $matching = array_values(array_filter(
            $prefs,
            static fn ($p) => $p['pref_name'] === $prefName
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['pref_value'])->toBe($prefValue);
    });
});
