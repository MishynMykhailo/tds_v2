<?php

/*
|--------------------------------------------------------------------------
| StreamTypes contract tests
|--------------------------------------------------------------------------
|
| Locks down the `streamTypes` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=streamTypes.listAsOptions`),
| run against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Pure reference/catalog, single action `listAsOptions`: stream types
| (`forced`/`regular`/`default`, see `Traffic\Model\Stream::TYPE_*`).
| Nothing to create/update/delete here.
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

describe('streamTypes.listAsOptions', function () {
    test('returns a non-empty catalog of stream types', function () {
        $response = $this->api->get('streamTypes.listAsOptions');
        expect($response->getStatusCode())->toBe(200);

        $types = ApiClient::json($response);
        expect($types)->toBeArray()->not->toBeEmpty();
    });
});
