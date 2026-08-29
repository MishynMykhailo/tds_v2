<?php

/*
|--------------------------------------------------------------------------
| StreamActions contract tests
|--------------------------------------------------------------------------
|
| Locks down the `streamActions` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=streamActions.index`),
| run against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Pure reference/catalog, single action `index`: the list of direct-action
| types (curl/show_text/show_html/local_file/404/to_campaign/frame/iframe/
| sub_id/do_nothing) available when a stream's `schema` is `ACTION`. Nothing
| to create/update/delete here.
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

describe('streamActions.index', function () {
    test('returns a non-empty catalog of action types', function () {
        $response = $this->api->get('streamActions.index');
        expect($response->getStatusCode())->toBe(200);

        $actions = ApiClient::json($response);
        expect($actions)->toBeArray()->not->toBeEmpty();
    });
});
