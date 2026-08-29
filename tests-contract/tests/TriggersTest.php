<?php

/*
|--------------------------------------------------------------------------
| Triggers contract tests
|--------------------------------------------------------------------------
|
| Locks down the `triggers` module contract documented in
| docs/legacy-reference/frontend/api/10.2_streams.md (`object=triggers`),
| run against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| `object=triggers` bundles three static reference catalogs for the trigger
| builder UI (`targets`/`conditions`/`actions`) plus a single real mutating
| action, `update`, which REPLACES the entire trigger list of one stream
| (`id` via query, `stream_id` under the hood) with whatever `triggers`
| array is posted - it is not an incremental add/remove.
|
| Every test here builds its own campaign+stream fixture first, exactly as
| the Streams suite does - never against a pre-existing id, since the target
| database is live and shared.
|
| Verified live against TDS_TEST_TARGET (the doc only names the three
| catalogs and the general shape, not their exact keys/values or the
| trigger config fields, so all of the below was checked against real
| responses rather than assumed):
|
| - `triggers.targets` is a flat {code: label} map: `stream` ("URL of
|   stream"), `landings` ("Landing Pages"), `offers` ("Offers"),
|   `selected_page` ("Another URL").
| - `triggers.conditions` is a flat {code: label} map: `not_respond`,
|   `contains`, `not_contains`, `av_detected`, `always`.
| - `triggers.actions` is a flat {code: label} map: `disable` ("Disable
|   stream"), `grab_from_page`, `replace_url`, `do_nothing`, `webhook`.
| - `triggers.update` validates a posted trigger config with (at least)
|   `target`, `condition`, `action` (each must be one of the codes above)
|   and `interval` (required, e.g. seconds) - a 406 with per-field
|   "Is required" / "Contains invalid value" errors is returned otherwise.
| - On success it returns 200 with the full, saved trigger list (not just
|   an echo of the input): each trigger gets a DB-assigned `id`/`oid`,
|   `stream_id`, and the full raw column set - `target`, `condition`,
|   `action`, `interval`, plus fields not supplied on this request that
|   default to null/0: `selected_page`, `pattern`, `next_run_at`,
|   `alternative_urls`, `grab_from_page`, `av_settings`, `reverse` ("0"),
|   `enabled` ("0"), `scan_page` ("0"). Numeric-looking values come back as
|   strings, consistent with the rest of this legacy backend's API.
| - The same saved list is what `streams.show`'s `triggers` association
|   subsequently returns for that stream (§10.2 StreamSerializer).
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

function expectNonEmptyCodeLabelMap(mixed $map): void
{
    expect($map)->toBeArray()->not->toBeEmpty();

    foreach ($map as $code => $label) {
        expect($code)->toBeString()->not->toBeEmpty();
        expect($label)->toBeString()->not->toBeEmpty();
    }
}

describe('triggers reference catalogs', function () {
    test('triggers.targets returns a non-empty {code: label} map, including the documented `stream` target', function () {
        $response = $this->api->get('triggers.targets');
        expect($response->getStatusCode())->toBe(200);

        $targets = ApiClient::json($response);
        expectNonEmptyCodeLabelMap($targets);
        expect($targets)->toHaveKey('stream');
    });

    test('triggers.conditions returns a non-empty {code: label} map, including the documented `always` condition', function () {
        $response = $this->api->get('triggers.conditions');
        expect($response->getStatusCode())->toBe(200);

        $conditions = ApiClient::json($response);
        expectNonEmptyCodeLabelMap($conditions);
        expect($conditions)->toHaveKey('always');
    });

    test('triggers.actions returns a non-empty {code: label} map, including the documented `disable` action', function () {
        $response = $this->api->get('triggers.actions');
        expect($response->getStatusCode())->toBe(200);

        $actions = ApiClient::json($response);
        expectNonEmptyCodeLabelMap($actions);
        expect($actions)->toHaveKey('disable');
    });
});

describe('triggers.update', function () {
    test('rejects a trigger config missing the required fields as a 406 with per-field validation errors', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id']);

        $response = $this->api->post('triggers.update', ['id' => (int) $stream['id']], [
            'triggers' => [[]],
        ]);

        expect($response->getStatusCode())->toBe(406);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('interval');
        expect($body)->toHaveKey('condition');
        expect($body)->toHaveKey('action');
        expect($body)->toHaveKey('target');
    });

    test('replaces a stream\'s trigger list, and the saved trigger is then reflected on streams.show', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id']);
        $streamId = (int) $stream['id'];

        // A freshly created stream has no triggers yet (§10.2, association
        // always present but empty).
        $before = ApiClient::json($this->api->get('streams.show', ['id' => $streamId]));
        expect($before['triggers'])->toBe([]);

        $response = $this->api->post('triggers.update', ['id' => $streamId], [
            'triggers' => [
                [
                    'target' => 'stream',
                    'condition' => 'always',
                    'action' => 'disable',
                    'interval' => 60,
                ],
            ],
        ]);
        expect($response->getStatusCode())->toBe(200);

        $saved = ApiClient::json($response);
        expect($saved)->toBeArray()->toHaveCount(1);

        $trigger = $saved[0];
        expect((int) $trigger['stream_id'])->toBe($streamId);
        expect($trigger['target'])->toBe('stream');
        expect($trigger['condition'])->toBe('always');
        expect($trigger['action'])->toBe('disable');
        expect((int) $trigger['interval'])->toBe(60);
        expect($trigger)->toHaveKey('id');

        // `update` replaces the association wholesale, so a subsequent
        // `streams.show` must reflect exactly the trigger just saved.
        $after = ApiClient::json($this->api->get('streams.show', ['id' => $streamId]));
        expect($after['triggers'])->toBeArray()->toHaveCount(1);

        $shownTrigger = $after['triggers'][0];
        expect($shownTrigger['id'])->toBe($trigger['id']);
        expect($shownTrigger['target'])->toBe('stream');
        expect($shownTrigger['condition'])->toBe('always');
        expect($shownTrigger['action'])->toBe('disable');
        expect((int) $shownTrigger['interval'])->toBe(60);
    });

    test('a second call fully replaces the previous trigger list rather than appending to it', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id']);
        $streamId = (int) $stream['id'];

        $firstResponse = $this->api->post('triggers.update', ['id' => $streamId], [
            'triggers' => [
                ['target' => 'stream', 'condition' => 'always', 'action' => 'disable', 'interval' => 60],
            ],
        ]);
        expect($firstResponse->getStatusCode())->toBe(200);

        $secondResponse = $this->api->post('triggers.update', ['id' => $streamId], [
            'triggers' => [
                ['target' => 'landings', 'condition' => 'not_respond', 'action' => 'do_nothing', 'interval' => 120],
            ],
        ]);
        expect($secondResponse->getStatusCode())->toBe(200);

        $saved = ApiClient::json($secondResponse);
        expect($saved)->toBeArray()->toHaveCount(1);
        expect($saved[0]['target'])->toBe('landings');
        expect($saved[0]['condition'])->toBe('not_respond');
        expect($saved[0]['action'])->toBe('do_nothing');
        expect((int) $saved[0]['interval'])->toBe(120);

        $shown = ApiClient::json($this->api->get('streams.show', ['id' => $streamId]));
        expect($shown['triggers'])->toBeArray()->toHaveCount(1);
        expect($shown['triggers'][0]['target'])->toBe('landings');
    });
});
