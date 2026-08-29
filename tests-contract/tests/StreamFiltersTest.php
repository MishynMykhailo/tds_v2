<?php

/*
|--------------------------------------------------------------------------
| StreamFilters contract tests
|--------------------------------------------------------------------------
|
| Locks down the `streamFilters` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=streamFilters`),
| run against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| `object=streamFilters` is a pure reference/catalog, not a CRUD resource:
| its only action is `filters`, backed by `FilterRepository::getFiltersAsOptions()`
| - a static list of ~24 filter types available to the stream condition-builder
| UI (country/region/city/language/browser/os/ip/isp/connection_type/device
| etc). There is nothing to create/update/delete here.
|
| Verified live against TDS_TEST_TARGET: each entry's type identifier lives
| under the `value` key (NOT `type` or `id` - the doc doesn't specify the
| exact key, so this was checked against a real response rather than
| assumed), e.g. {"value": "country", "tooltip": null, "modes": {"accept":
| "IS", "reject": "IS NOT"}, "group": "filters.groups.geo", "template": "...",
| "header_template": null, "defaults": null}.
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

describe('streamFilters.filters', function () {
    test('returns a non-empty catalog of filter types, each identified by a `value` key', function () {
        $response = $this->api->get('streamFilters.filters');
        expect($response->getStatusCode())->toBe(200);

        $filters = ApiClient::json($response);
        expect($filters)->toBeArray()->not->toBeEmpty();

        $values = [];
        foreach ($filters as $filter) {
            expect($filter)->toBeArray();

            // Every filter type must carry a non-empty string identifier
            // under `value` - this is what the stream condition-builder UI
            // and StreamFilter payloads reference (e.g. "country", "ip",
            // "connection_type", "browser", ...).
            expect($filter)->toHaveKey('value');
            expect($filter['value'])->toBeString()->not->toBeEmpty();

            // Rest of the documented per-type shape, present on every entry
            // even when the value itself is null (e.g. `tooltip`, `defaults`,
            // and - verified live - `modes` too, e.g. for `empty_referrer`).
            expect($filter)->toHaveKeys(['tooltip', 'modes', 'group', 'template', 'header_template', 'defaults']);
            expect($filter['modes'] === null || is_array($filter['modes']))->toBeTrue();

            $values[] = $filter['value'];
        }

        // Identifiers are unique and include a representative sample of the
        // documented ~24 targeting dimensions (geo/device/network/parameters).
        expect($values)->toBe(array_unique($values));
        expect($values)->toContain('country');
        expect($values)->toContain('ip');
        expect($values)->toContain('browser');
        expect($values)->toContain('connection_type');
    });
});
