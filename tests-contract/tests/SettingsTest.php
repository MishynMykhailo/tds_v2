<?php

/*
|--------------------------------------------------------------------------
| Settings contract tests
|--------------------------------------------------------------------------
|
| Locks down the `settings` module contract documented in
| docs/legacy-reference/frontend/api/10.12_settings.md (`SettingsController`),
| run against the backend named by TDS_TEST_TARGET (see
| tests/Support/ApiClient.php).
|
| Object/action names verified live against the legacy source
| (application/Component/Settings/Initializer.php registers the controller
| under the exact key "settings" -
| `$repo->register("settings", new Controller\SettingsController());` -
| and application/Component/Settings/Controller/SettingsController.php
| defines `indexAction`/`findAction`/`updateAction`/`configAction`/
| `getAuxiliaryDataAction`/`changeLanguageAction`), so this suite exercises
| `settings.index`, `settings.find` and `settings.update`.
|
| `settings.index` (admin-only, verified in SettingsController::indexAction
| via `$this->isAdmin()`) returns ALL settings as a flat `{key: value}` hash
| via `SettingsRepository::allAsHash()` - not a list of objects.
|
| `settings.update` is POST-only (`SettingsController::updateAction` throws
| a 500-class "Must be post request" `Core\Application\Exception\Error` for
| non-POST) and, per `SettingsService::updateValues()` ->
| `SettingsRepository::allAsHash(array_keys($newSettings))`, echoes back
| ONLY the keys that were part of the update payload - not the full
| settings hash.
|
| `campaign_autosave` (verified in application/data/data.sql: seeded as
| `('campaign_autosave', '0')`, and surfaced in the settings UI per
| application/Component/Settings/translations/en.php as "Enable campaign
| autosave") is used as the round-trip key here because it is a harmless
| boolean-ish UI toggle with no side effects on stats/licensing/other
| entities - unlike e.g. `cache_storage` or `lp_dir`, which
| `SettingsService::updateValues()` treats specially (cache re-warmup /
| local landing folder rename). All values are stored and returned as
| strings (verified in the data.sql seed: `'0'`, not `0`).
|
| This suite restores whatever `campaign_autosave` value it found before
| its own writes, since `settings` is global, shared installation state
| (not per-user) - see the file-level note in ProfileTest.php/
| UserPreferencesTest.php for the same shared-live-backend caveat.
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

describe('settings.index', function () {
    test('returns a non-empty {key: value} hash of every setting', function () {
        $response = $this->api->get('settings.index');
        expect($response->getStatusCode())->toBe(200);

        $settings = ApiClient::json($response);
        expect($settings)->toBeArray()->not->toBeEmpty();

        // It's a hash keyed by setting name, not a list - assert on a
        // well-known, always-seeded key rather than positional structure.
        expect($settings)->toHaveKey('campaign_autosave');
    });

    test('the `only` param filters the hash down to just the requested keys', function () {
        $response = $this->api->get('settings.index', ['only' => 'campaign_autosave']);
        expect($response->getStatusCode())->toBe(200);

        $settings = ApiClient::json($response);
        expect($settings)->toBeArray();
        expect($settings)->toHaveKey('campaign_autosave');
        expect($settings)->toHaveCount(1);
    });
});

describe('settings.find', function () {
    test('returns a single {key, value} pair for the given key', function () {
        $response = $this->api->get('settings.find', ['key' => 'campaign_autosave']);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKeys(['key', 'value']);
        expect($body['key'])->toBe('campaign_autosave');
    });
});

describe('settings.update', function () {
    test('updates campaign_autosave and the new value round-trips via settings.find/.index', function () {
        // Read the current value first so we can restore it - this is
        // global installation state, shared with any human/other agent
        // poking the same live backend.
        $before = ApiClient::json($this->api->get('settings.find', ['key' => 'campaign_autosave']));
        $originalValue = $before['value'];

        // Flip to the opposite of whatever's currently stored so the
        // update is guaranteed to be an observable change either way.
        $newValue = ($originalValue == '1') ? '0' : '1';

        try {
            $updateResponse = $this->api->post('settings.update', [], [
                'campaign_autosave' => $newValue,
            ]);
            expect($updateResponse->getStatusCode())->toBe(200);

            $updateBody = ApiClient::json($updateResponse);
            expect($updateBody)->toBeArray();
            // updateAction() echoes back only the keys that were part of
            // the request payload (allAsHash(array_keys($newSettings))).
            expect($updateBody)->toHaveKey('campaign_autosave');
            expect((string) $updateBody['campaign_autosave'])->toBe($newValue);

            // Re-read independently via settings.find to confirm it was
            // actually persisted, not just echoed by the update response.
            $findResponse = $this->api->get('settings.find', ['key' => 'campaign_autosave']);
            expect($findResponse->getStatusCode())->toBe(200);
            $findBody = ApiClient::json($findResponse);
            expect((string) $findBody['value'])->toBe($newValue);

            // And confirm it also shows up correctly via settings.index.
            $indexResponse = $this->api->get('settings.index', ['only' => 'campaign_autosave']);
            $indexBody = ApiClient::json($indexResponse);
            expect((string) $indexBody['campaign_autosave'])->toBe($newValue);
        } finally {
            // Always restore the original value, even if an assertion
            // above failed, so this test doesn't leave global installation
            // state mutated for whoever runs next.
            $this->api->post('settings.update', [], [
                'campaign_autosave' => $originalValue,
            ]);
        }
    });

    test('rejects a non-POST request', function () {
        // SettingsController::updateAction() explicitly checks
        // $this->isPost() and throws before touching any data - verified
        // in application/Component/Settings/Controller/SettingsController.php.
        $response = $this->api->get('settings.update', ['campaign_autosave' => '1']);

        expect($response->getStatusCode())->not->toBe(200);
    });
});
