<?php

/*
|--------------------------------------------------------------------------
| StreamSchemas contract tests
|--------------------------------------------------------------------------
|
| Locks down the `streamSchemas` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=streamSchemas.listAsOptions`,
| `StreamSchemaRepository`), run against the backend named by TDS_TEST_TARGET
| (see tests/Support/ApiClient.php).
|
| Pure reference/catalog, single action `listAsOptions`: the three
| available stream schemas (`LANDINGS`, `REDIRECT`/`OFFERS`, `ACTION`, see
| `Traffic\Model\BaseStream`). Nothing to create/update/delete here.
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

describe('streamSchemas.listAsOptions', function () {
    test('returns a non-empty catalog of stream schemas', function () {
        $response = $this->api->get('streamSchemas.listAsOptions');
        expect($response->getStatusCode())->toBe(200);

        $schemas = ApiClient::json($response);
        expect($schemas)->toBeArray()->not->toBeEmpty();
    });
});
