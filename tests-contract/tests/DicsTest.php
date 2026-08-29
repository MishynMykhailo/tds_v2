<?php

/*
|--------------------------------------------------------------------------
| Dics contract tests
|--------------------------------------------------------------------------
|
| Locks down the `dics` module contract documented in
| docs/legacy-reference/frontend/api/10.12_settings.md (`DicsController`),
| run against the backend named by TDS_TEST_TARGET (see
| tests/Support/ApiClient.php).
|
| Object/action name verified live against the legacy source
| (application/Component/Settings/Initializer.php registers the controller
| under the exact key "dics" -
| `$repo->register("dics", new Controller\DicsController());` - and
| application/Component/Settings/Controller/DicsController.php defines only
| a single action, `currenciesAction`), so this suite exercises
| `dics.currencies`.
|
| `dics.currencies` (no auth/admin gate on DicsController, verified live -
| unlike `settings.index`) just proxies
| `Core\Currency\Repository\CurrenciesRepository::getCurrencies()`, which
| (per application/Core/Currency/Repository/CurrenciesRepository.php)
| returns a flat LIST of `{value, name}` pairs built from the static
| currency data file - e.g. `{"value":"USD","name":"USD ($)"}` - NOT a hash
| keyed by currency code. This is a read-only, static reference dictionary:
| no fixtures/cleanup needed.
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

describe('dics.currencies', function () {
    test('returns a non-empty list of {value, name} currency pairs', function () {
        $response = $this->api->get('dics.currencies');
        expect($response->getStatusCode())->toBe(200);

        $currencies = ApiClient::json($response);
        expect($currencies)->toBeArray()->not->toBeEmpty();

        // A flat list (sequential array), not a hash keyed by currency code.
        expect(array_is_list($currencies))->toBeTrue();

        foreach ($currencies as $currency) {
            expect($currency)->toBeArray();
            expect($currency)->toHaveKeys(['value', 'name']);
        }

        // USD is part of the static currency data file shipped with the
        // legacy backend - a stable entry to assert against without
        // depending on the full/exact list staying fixed.
        $values = array_column($currencies, 'value');
        expect($values)->toContain('USD');
    });
});
