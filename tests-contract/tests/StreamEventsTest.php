<?php

/*
|--------------------------------------------------------------------------
| StreamEvents contract tests
|--------------------------------------------------------------------------
|
| Locks down the `streamEvents` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=streamEvents`),
| run against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| `index` (`stream_id`,`limit`,`page`) lists the event log for one stream -
| documented side effect: reading marks unread events as read. `clear`
| wipes a stream's event log.
|
| NOT tested here: actual event creation. Events are produced by the
| backend itself (trigger firings etc.) as part of the click pipeline
| (§11), not through any direct "create event" API action - no simple way
| to trigger that from this contract suite was found, so this file only
| locks down that a freshly created stream's log starts empty and that
| both actions respond with 200 rather than erroring.
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

describe('streamEvents.index / streamEvents.clear', function () {
    test('a freshly created stream has an empty event log, and clear does not error', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id']);
        $streamId = (int) $stream['id'];

        $indexResponse = $this->api->get('streamEvents.index', ['stream_id' => $streamId]);
        expect($indexResponse->getStatusCode())->toBe(200);

        // Verified live: the response is a paginated envelope
        // {"total": "0", "items": []}, not a bare array.
        $events = ApiClient::json($indexResponse);
        expect($events)->toBeArray()->toHaveKey('items');
        expect((int) $events['total'])->toBe(0);
        expect($events['items'])->toBeArray()->toBeEmpty();

        $clearResponse = $this->api->post('streamEvents.clear', ['stream_id' => $streamId]);
        expect($clearResponse->getStatusCode())->toBe(200);
    });
});
