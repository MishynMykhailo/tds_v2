<?php

/*
|--------------------------------------------------------------------------
| Streams contract tests
|--------------------------------------------------------------------------
|
| Locks down the `streams` module contract documented in
| docs/legacy-reference/frontend/backend_api_reference.md §10.2, run
| against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Streams are always children of a campaign, so every test here builds its
| own campaign fixture first (`campaigns.create`), then its own stream(s)
| inside it (`streams.create` / `streams.createInCampaign`), exactly as the
| Campaigns suite does - never against a pre-existing id, since the target
| database is live and shared.
|
| §2.2 routing note verified live against TDS_TEST_TARGET: the doc table in
| §10.2 labels the archive row "deleteAction" (the PHP method name on
| StreamsController), but the actual `object=` route parameter is `delete`
| (per the `<action>Action` convention in §2.2 - `object=streams.deleteAction`
| 404s with "Controller action deleteActionAction is not defined").
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

// §10.2: StreamSerializer has $_fields = true (all raw model fields pass
// through), verified against the `tds_streams` table columns.
const STREAM_RAW_FIELDS = [
    'id', 'type', 'name', 'campaign_id', 'group_id', 'position',
    'action_options', 'comments', 'state', 'action_type', 'action_payload',
    'schema', 'collect_clicks', 'filter_or', 'weight', 'chance',
];

// §10.2: always removed by StreamSerializer::extra(), regardless of args.
const STREAM_ALWAYS_REMOVED_FIELDS = ['landing_id', 'offer_id', 'status', 'updated_at'];

// §10.2: always attached via _addAssociation(), even when empty - this was
// a previously-fixed bug (see FIXES_LOG) where these were lost on read.
const STREAM_ASSOCIATION_FIELDS = ['filters', 'triggers', 'landings', 'offers'];

function expectStreamHasAssociations(array $stream): void
{
    foreach (STREAM_ASSOCIATION_FIELDS as $field) {
        expect($stream)->toHaveKey($field);
        expect($stream[$field])->toBeArray();
    }
}

describe('streams.create / streams.show', function () {
    test('a freshly created stream round-trips the full documented raw field set via show, with associations always present', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $campaignId = (int) $campaign['id'];

        $created = Fixtures::createStream($this->api, $campaignId, [
            'name' => 'Round-trip stream',
        ]);

        expect((int) $created['campaign_id'])->toBe($campaignId);
        expect($created['action_type'])->toBe('do_nothing');
        expect($created['schema'])->toBe('redirect');
        expect($created['state'])->toBe('active');
        expectStreamHasAssociations($created);

        $streamId = (int) $created['id'];

        $response = $this->api->get('streams.show', ['id' => $streamId]);
        expect($response->getStatusCode())->toBe(200);

        $shown = ApiClient::json($response);
        expect($shown)->toBeArray();
        expect((int) $shown['id'])->toBe($streamId);
        expect((int) $shown['campaign_id'])->toBe($campaignId);
        expect($shown['name'])->toBe('Round-trip stream');

        foreach (STREAM_RAW_FIELDS as $field) {
            expect($shown)->toHaveKey($field);
        }

        foreach (STREAM_ALWAYS_REMOVED_FIELDS as $field) {
            expect($shown)->not->toHaveKey($field);
        }

        expectStreamHasAssociations($shown);
    });

    test('rejects create with missing required action_type/schema as a 406 with per-field validation errors', function () {
        $campaign = Fixtures::createCampaign($this->api);

        $response = $this->api->post('streams.create', [], [
            'campaign_id' => (int) $campaign['id'],
        ]);

        // §10.2 / StreamValidator: campaign_id, action_type, schema are required.
        expect($response->getStatusCode())->toBe(406);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('action_type');
        expect($body)->toHaveKey('schema');
    });

    test('rejects show with no id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('streams.show');

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });

    test('rejects show with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('streams.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('streams.index', function () {
    test('lists all streams of a campaign, in campaign order, associations attached', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $campaignId = (int) $campaign['id'];

        $first = Fixtures::createStream($this->api, $campaignId, ['name' => 'Index stream one']);
        $second = Fixtures::createStream($this->api, $campaignId, ['name' => 'Index stream two']);

        $response = $this->api->get('streams.index', ['campaign_id' => $campaignId]);
        expect($response->getStatusCode())->toBe(200);

        $streams = ApiClient::json($response);
        expect($streams)->toBeArray()->not->toBeEmpty();

        $ids = array_map(static fn ($s) => (int) $s['id'], $streams);
        expect($ids)->toContain((int) $first['id']);
        expect($ids)->toContain((int) $second['id']);

        foreach ($streams as $stream) {
            expect((int) $stream['campaign_id'])->toBe($campaignId);
            expectStreamHasAssociations($stream);
        }
    });

    test('rejects a request with no campaign_id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('streams.index');

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('streams.update', function () {
    test('updates a stream field and the change persists on a subsequent show', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id']);
        $streamId = (int) $stream['id'];

        $response = $this->api->post('streams.update', ['id' => $streamId], [
            'name' => 'Renamed via streams.update',
        ]);
        expect($response->getStatusCode())->toBe(200);

        $updated = ApiClient::json($response);
        expect($updated['name'])->toBe('Renamed via streams.update');
        expectStreamHasAssociations($updated);

        $shown = ApiClient::json($this->api->get('streams.show', ['id' => $streamId]));
        expect($shown['name'])->toBe('Renamed via streams.update');
    });
});

describe('streams.delete (archives, does not physically delete)', function () {
    test('archiving a stream keeps the row readable via show with its state changed, but removes it from the campaign index', function () {
        $campaign = Fixtures::createCampaign($this->api);
        $campaignId = (int) $campaign['id'];
        $stream = Fixtures::createStream($this->api, $campaignId);
        $streamId = (int) $stream['id'];

        // Sanity: visible in the campaign's active stream list before archiving.
        $before = ApiClient::json($this->api->get('streams.index', ['campaign_id' => $campaignId]));
        $idsBefore = array_map(static fn ($s) => (int) $s['id'], $before);
        expect($idsBefore)->toContain($streamId);

        $deleteResponse = $this->api->post('streams.delete', ['id' => $streamId]);
        expect($deleteResponse->getStatusCode())->toBe(200);

        // §10.2: deleteAction really calls archiveStream() -> EntityService::archive(),
        // which sets state=deleted (the "archive" bucket state, see Core\Entity\State) -
        // it never physically removes the row. `show` must keep returning it.
        $afterShowResponse = $this->api->get('streams.show', ['id' => $streamId]);
        expect($afterShowResponse->getStatusCode())->toBe(200);

        $afterShow = ApiClient::json($afterShowResponse);
        expect((int) $afterShow['id'])->toBe($streamId);
        expect($afterShow['state'])->toBe('deleted');

        // But it's excluded from the active campaign stream list.
        $after = ApiClient::json($this->api->get('streams.index', ['campaign_id' => $campaignId]));
        $idsAfter = array_map(static fn ($s) => (int) $s['id'], $after);
        expect($idsAfter)->not->toContain($streamId);
    });
});

describe('streams.listAsOptions', function () {
    test('a freshly created active stream appears with the documented {id,group,name} option shape', function () {
        $campaign = Fixtures::createCampaign($this->api, ['name' => 'Options campaign ' . bin2hex(random_bytes(3))]);
        $stream = Fixtures::createStream($this->api, (int) $campaign['id'], ['name' => 'Options probe stream']);
        $streamId = (int) $stream['id'];

        $response = $this->api->get('streams.listAsOptions');
        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray()->not->toBeEmpty();

        foreach ($options as $option) {
            expect($option)->toBeArray();
            expect($option)->toHaveKeys(['id', 'group', 'name']);
        }

        $matching = array_values(array_filter(
            $options,
            static fn ($o) => (int) $o['id'] === $streamId
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['group'])->toBe($campaign['name']);
        expect($matching[0]['name'])->toBe('[' . $streamId . '] Options probe stream');
    });
});
