<?php

/*
|--------------------------------------------------------------------------
| FavouriteStreams contract tests
|--------------------------------------------------------------------------
|
| Locks down the `favouriteStreams` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=favouriteStreams`),
| run against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| A real (small) CRUD-ish resource: a per-user list of favourite stream ids,
| ACL-gated through the stream's parent campaign. `index` lists favourites,
| `add`/`remove` take `stream_id`.
|
| Every test builds its own campaign+stream fixture first - never against a
| pre-existing id, since the target database is live and shared.
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

function favouriteStreamIds(array $favourites): array
{
    // Be liberal about the exact item shape (doc doesn't pin it down): each
    // entry might be a bare stream id, or an object carrying `stream_id`.
    return array_map(
        fn ($item) => is_array($item) ? (int) ($item['stream_id'] ?? $item['id'] ?? 0) : (int) $item,
        $favourites
    );
}

describe('favouriteStreams add/index/remove', function () {
    test('add puts a stream into favouriteStreams.index, remove takes it back out', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id']);
        $streamId = (int) $stream['id'];

        $addResponse = $this->api->post('favouriteStreams.add', ['stream_id' => $streamId]);
        expect($addResponse->getStatusCode())->toBe(200);

        $afterAdd = ApiClient::json($this->api->get('favouriteStreams.index'));
        expect($afterAdd)->toBeArray();
        expect(favouriteStreamIds($afterAdd))->toContain($streamId);

        $removeResponse = $this->api->post('favouriteStreams.remove', ['stream_id' => $streamId]);
        expect($removeResponse->getStatusCode())->toBe(200);

        $afterRemove = ApiClient::json($this->api->get('favouriteStreams.index'));
        expect(favouriteStreamIds($afterRemove))->not->toContain($streamId);
    });
});
