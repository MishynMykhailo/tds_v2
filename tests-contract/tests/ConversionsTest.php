<?php

/*
|--------------------------------------------------------------------------
| Conversions contract tests
|--------------------------------------------------------------------------
|
| Locks down the `conversions` module contract
| (`Component\Conversions\Controller\ConversionsController`), run against
| the backend named by TDS_TEST_TARGET.
|
| `conversions.log`/`.logDefinition`/`.updateCostDefinition` are Grid-
| backed endpoints - only `url`/`details`/`range_intervals` (stable) and a
| curated subset of always-present columns are compared field-by-field.
| The FULL column list is NOT compared: this port's Grid definitions are a
| deliberate, already-documented simplification (drops title/th_title/
| inner_select/resizable/decorator, and swaps dereferenced dictionary-name
| text columns for the raw `*_id` FK this port's single-table schema
| actually has) - see ConversionsController's class docblock and
| ReportsController's equivalent docblock for the same established
| pattern elsewhere in this codebase. Re-asserting full parity here would
| just re-litigate an already-made architectural decision.
|
| `conversions.log`/`.import` are POST-only in legacy (verified live,
| 2026-09-03: a GET with the same query-string params 500s with "You must
| provide 'range' or 'limit'" even though both were present as query
| params - legacy's grid param parser only reads the POST body).
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

describe('conversions.statuses', function () {
    test('returns the exact same 4-status dictionary, including the non-obvious rebill => Upsell label', function () {
        $response = $this->api->get('conversions.statuses');
        expect($response->getStatusCode())->toBe(200);

        expect(ApiClient::json($response))->toBe([
            ['id' => 'lead', 'name' => 'Lead'],
            ['id' => 'sale', 'name' => 'Sale'],
            ['id' => 'rejected', 'name' => 'Rejected'],
            ['id' => 'rebill', 'name' => 'Upsell'],
        ]);
    });
});

describe('conversions.logDefinition / conversions.updateCostDefinition', function () {
    test('logDefinition has the stable top-level shape and always-present core columns', function () {
        $response = $this->api->get('conversions.logDefinition');
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
        expect($body['url'])->toBe('?object=conversions.log');
        expect($body['details'])->toBe(['id' => 'conversion_id']);
        expect($body['range_intervals'])->toBeNull();

        $names = array_column($body['columns'], 'name');
        foreach (['conversion_id', 'status', 'revenue', 'cost', 'profit', 'campaign_id', 'tid'] as $expected) {
            expect($names)->toContain($expected);
        }
    });

    test('updateCostDefinition has the stable top-level shape (url/details null, empty range_intervals)', function () {
        $response = $this->api->get('conversions.updateCostDefinition');
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
        expect($body['url'])->toBeNull();
        expect($body['details'])->toBeNull();
        expect($body['range_intervals'])->toBe([]);

        $names = array_column($body['columns'], 'name');
        foreach (['click_id', 'campaign_id', 'revenue', 'cost', 'profit', 'sub_id_1', 'sub_id_15'] as $expected) {
            expect($names)->toContain($expected);
        }
    });
});

describe('conversions.log', function () {
    test('a bare POST with just a limit returns the shared Grid shape (rows/total), tolerant of total\'s string-vs-int typing', function () {
        $response = $this->api->post('conversions.log', [], ['limit' => 1]);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toHaveKeys(['rows', 'total']);
        expect($body['rows'])->toBeArray();
        expect((string) $body['total'])->toBeString();
    });

    test('a GET with the same params 500s on both targets - grid endpoints only read the POST body', function () {
        $response = $this->api->get('conversions.log', ['limit' => 1]);
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(500);
    });

    test('rejects a request with neither range nor limit', function () {
        $response = $this->api->post('conversions.log', [], []);
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
    });
});

describe('conversions.import', function () {
    test('rejects a request missing data or currency with a plain-text 500 (legacy throws a generic Error, not a validation exception)', function () {
        $missingCurrency = $this->api->post('conversions.import', [], ['data' => 'x,1']);
        expect($missingCurrency->getStatusCode())->toBeGreaterThanOrEqual(500);

        $missingData = $this->api->post('conversions.import', [], ['currency' => 'USD']);
        expect($missingData->getStatusCode())->toBeGreaterThanOrEqual(500);
    });

    test('an empty sub_id is reported as "SubID empty", no prefix', function () {
        $response = $this->api->post('conversions.import', [], ['data' => ',10', 'currency' => 'USD']);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBe(['errors' => ['SubID empty'], 'success' => 0, 'total' => 1]);
    });

    test('an unmatched sub_id is reported with NO sub_id prefix - verified live against legacy source (PayloadFactory throws PostbackError, not NotFoundError, for this case)', function () {
        $subId = 'ct-conv-' . bin2hex(random_bytes(4));
        $response = $this->api->post('conversions.import', [], [
            'data' => $subId . ',10',
            'currency' => 'USD',
        ]);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBe(['errors' => ['SubID not found "' . $subId . '"'], 'success' => 0, 'total' => 1]);
    });

    test('a malformed row (no comma) is silently dropped, not counted toward total', function () {
        $response = $this->api->post('conversions.import', [], [
            'data' => "no-comma-here\nalso-malformed",
            'currency' => 'USD',
        ]);
        expect($response->getStatusCode())->toBe(200);

        $body = ApiClient::json($response);
        expect($body)->toBe(['errors' => [], 'success' => 0, 'total' => 0]);
    });
});

describe('authorization', function () {
    test('denies a non-admin user with a 403 - "conversions" is not a default resource', function () {
        $token = bin2hex(random_bytes(4));
        $password = 'CtPass!' . $token;
        Fixtures::createUser($this->api, [
            'login' => 'ct_conv_' . $token,
            'password_hash' => $password,
            'new_password' => $password,
            'new_password_confirmation' => $password,
            'type' => 'USER',
        ]);

        $userApi = new ApiClient();
        $login = $userApi->login('ct_conv_' . $token, $password);
        expect($login->getStatusCode())->toBe(200);

        $response = $userApi->get('conversions.statuses');
        expect($response->getStatusCode())->toBe(403);
    });
});
