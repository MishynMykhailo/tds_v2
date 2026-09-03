<?php

/*
|--------------------------------------------------------------------------
| Reports contract tests
|--------------------------------------------------------------------------
|
| Locks down the `reports` module contract
| (`Component\Reports\Controller\ReportsController`), run against the
| backend named by TDS_TEST_TARGET.
|
| `reports.definition`/`.columnsAsOptions` are NOT compared field-by-field
| in full - same already-documented, deliberate simplification as
| Conversions/GeoDb's *Definition endpoints (this port drops i18n/
| inner_select/dereferenced dictionary-name columns; ReportsController's
| own class docblock covers exactly why). Only the stable top-level shape
| and a curated set of always-present columns are asserted.
|
| `reports.build`/`.summary` are POST-only Grid-backed endpoints sharing
| `App\Services\Grid\QueryParams`/`GridBuilder` with conversions.log - see
| that module's contract test for the "GET 500s, range/limit required"
| notes, not repeated here.
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

describe('reports.definition / reports.columnsAsOptions', function () {
    test('definition has the stable top-level shape and always-present core columns', function () {
        $response = $this->api->get('reports.definition');
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
        expect($body['url'])->toBe('?object=reports.build');
        expect($body['details'])->toBeNull();
        expect($body['range_intervals'])->toBeNull();

        $names = array_column($body['columns'], 'name');
        foreach (['clicks', 'revenue', 'cost', 'profit', 'campaign_id', 'datetime'] as $expected) {
            expect($names)->toContain($expected);
        }
    });

    test('columnsAsOptions returns a non-empty list of {category, name, value} options', function () {
        $response = $this->api->get('reports.columnsAsOptions');
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->not->toBeEmpty();
        foreach ($body as $option) {
            expect($option)->toHaveKeys(['category', 'name', 'value']);
        }

        $values = array_column($body, 'value');
        expect($values)->toContain('clicks', 'campaign_id');
    });
});

describe('reports.build / reports.summary — metrics-only column selection', function () {
    test('a metrics-only request (no explicit `columns`) restricts the row shape to just those metrics, grouped', function () {
        $response = $this->api->post('reports.build', [], [
            'metrics' => ['clicks'],
            'grouping' => ['campaign_id'],
            'limit' => 50,
        ]);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toHaveKeys(['rows', 'total']);
        foreach ($body['rows'] as $row) {
            expect(array_keys($row))->toBe(['campaign_id', 'clicks']);
        }
    });

    test('a metrics-only summary request returns ONLY the requested metric, not the full fixed summary shape', function () {
        $response = $this->api->post('reports.summary', [], [
            'metrics' => ['clicks'],
            'limit' => 1,
        ]);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect(array_keys($body))->toBe(['clicks']);
    });

    test('rejects a request with neither range nor limit', function () {
        $response = $this->api->post('reports.build', [], ['metrics' => ['clicks']]);
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(500);
    });
});

describe('reports.parameterAliases / reports.statsForCampaign', function () {
    test('both give the exact legacy "Traffic\\Model\\Campaign #<id> not found" message for a non-existent campaign', function () {
        $missingId = 999999999;

        $aliases = $this->api->get('reports.parameterAliases', ['campaign_id' => $missingId]);
        expect($aliases->getStatusCode())->toBe(404);
        expect(ApiClient::json($aliases)['error'])->toBe("Traffic\\Model\\Campaign #{$missingId} not found");

        $stats = $this->api->post('reports.statsForCampaign', [], ['campaign_id' => $missingId]);
        expect($stats->getStatusCode())->toBe(404);
        expect(ApiClient::json($stats)['error'])->toBe("Traffic\\Model\\Campaign #{$missingId} not found");
    });

    test('statsForCampaign for a real campaign with no clicks falls back to the {"null": {zeros}} shape', function () {
        $campaign = Fixtures::createCampaign($this->api);

        try {
            $response = $this->api->post('reports.statsForCampaign', [], [
                'campaign_id' => $campaign['id'],
                'range' => ['interval' => 'today'],
            ]);
            expect($response->getStatusCode())->toBe(200);

            $body = ApiClient::json($response);
            expect($body)->toBe(['null' => ['clicks' => 0, 'stream_unique_clicks' => 0, 'bots' => 0]]);
        } finally {
            $this->api->post('campaigns.delete', [], ['id' => $campaign['id']]);
        }
    });

    test('parameterAliases returns an empty list for a campaign with no parameter aliases set', function () {
        $campaign = Fixtures::createCampaign($this->api);

        try {
            $response = $this->api->get('reports.parameterAliases', ['campaign_id' => $campaign['id']]);
            expect($response->getStatusCode())->toBe(200);
            expect(ApiClient::json($response))->toBe([]);
        } finally {
            $this->api->post('campaigns.delete', [], ['id' => $campaign['id']]);
        }
    });
});
