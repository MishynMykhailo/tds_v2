<?php

/*
|--------------------------------------------------------------------------
| Campaigns contract tests
|--------------------------------------------------------------------------
|
| Locks down the `campaigns` module contract documented in
| docs/legacy-reference/frontend/backend_api_reference.md §10.1, run
| against the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Every regression test below creates its OWN campaign fixture via
| `campaigns.create` (see tests/Support/Fixtures.php) before reading/
| asserting anything - it never depends on a specific pre-existing row.
| The target database is live and mutable (shared with humans clicking
| around and other agents), so pinning assertions to a fixed id is
| fragile: it broke once already when a background agent's autosave
| changed campaign id=4's alias mid-session.
|
| The one exception is the `smoke` group below: a deliberately separate,
| non-regression connectivity check against the legacy fixture campaign
| id=4 ("qbrtcz2"). It is NOT part of the main contract suite and is
| allowed to fail/be skipped if that row no longer exists - run it
| explicitly with `--group=smoke` when you want to sanity-check that
| specific historical fixture is still there.
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

// §10.1: CampaignSerializer has $_fields = true (all raw model fields pass through),
// present on both the extended `create`/`show` responses.
const CAMPAIGN_RAW_FIELDS = [
    'id', 'alias', 'name', 'type', 'uniqueness_method', 'cookies_ttl',
    'action_type', 'action_payload', 'action_for_bots', 'bot_redirect_url',
    'bot_text', 'action_tracking_disabled', 'position', 'state', 'updated_at',
    'cost_type', 'cost_value', 'cost_currency', 'group_id', 'bind_visitors',
    'traffic_source_id', 'token', 'cost_auto', 'domain_id', 'notes',
    'parameters', 'uniqueness_use_cookies', 'traffic_loss',
];

describe('campaigns.create / campaigns.show', function () {
    test('a freshly created campaign round-trips the full documented field set via show, including domain', function () {
        $created = Fixtures::createCampaign($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('campaigns.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $campaign = ApiClient::json($response);
        expect($campaign)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $campaign['id'])->toBe($id);
        expect($campaign['alias'])->toBe($created['alias']);
        expect($campaign['name'])->toBe($created['name']);

        foreach (CAMPAIGN_RAW_FIELDS as $field) {
            expect($campaign)->toHaveKey($field);
        }

        // §10.1: `domain` is resolved from domain_id and present even at the
        // non-extended serialization level (it's the one field added there).
        expect($campaign)->toHaveKey('domain');

        // §10.1: extended=true is used by `show`, adding group/streams_count/ts/postbacks.
        expect($campaign)->toHaveKey('group');
        expect($campaign)->toHaveKey('streams_count');
        expect($campaign)->toHaveKey('ts');
        expect($campaign)->toHaveKey('postbacks');
        expect($campaign['postbacks'])->toBeArray();

        // §10.1: `mode` is an internal field explicitly unset by the serializer.
        expect($campaign)->not->toHaveKey('mode');

        // §10.1: `withStreams` was not requested, so the nested `streams` list
        // must not be attached (it's opt-in, only honored by `show`).
        expect($campaign)->not->toHaveKey('streams');
    });

    test('rejects a request with no id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('campaigns.show');

        // §6: NotFoundError -> HTTP 404, body {"error": ..., "stacktrace": ...}.
        // The stacktrace is documented as ALWAYS present, not a debug-mode artifact.
        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
        expect($body['error'])->toBeString()->not->toBeEmpty();
        expect($body['stacktrace'])->toBeString()->not->toBeEmpty();
    });

    test('rejects a request with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('campaigns.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('campaigns.create: legacy cost_type=CPV remap', function () {
    test('cost_type=CPV is substituted for CPC on the fly, both on create and on a subsequent show', function () {
        // §10.1: legacy cost_type=CPV is always substituted for CPC on the fly -
        // a real backend must never surface CPV to the client, even though the
        // raw value CAN be written (CampaignValidator has no "in" rule for
        // cost_type, so the legacy backend accepts CPV straight through on
        // create - it's the serializer's job to hide it again on the way out).
        $created = Fixtures::createCampaign($this->api, [
            'cost_type' => 'CPV',
            'cost_value' => 5,
        ]);

        expect($created['cost_type'])->not->toBe('CPV');
        expect($created['cost_type'])->toBe('CPC');

        $response = $this->api->get('campaigns.show', ['id' => (int) $created['id']]);
        expect($response->getStatusCode())->toBe(200);

        $shown = ApiClient::json($response);
        expect($shown['cost_type'])->not->toBe('CPV');
        expect($shown['cost_type'])->toBe('CPC');
    });
});

describe('campaigns.index', function () {
    test('a freshly created campaign appears with id/name/state on each element', function () {
        $created = Fixtures::createCampaign($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('campaigns.index');

        expect($response->getStatusCode())->toBe(200);

        $campaigns = ApiClient::json($response);
        expect($campaigns)->toBeArray()->not->toBeEmpty();

        foreach ($campaigns as $campaign) {
            expect($campaign)->toBeArray();
            expect($campaign)->toHaveKey('id');
            expect($campaign)->toHaveKey('name');
            expect($campaign)->toHaveKey('state');
        }

        $matching = array_values(array_filter(
            $campaigns,
            static fn ($c) => (int) $c['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect($matching[0]['state'])->toBe('active');
    });
});

describe('campaigns.listAsOptions', function () {
    test('a freshly created campaign appears with the documented {id,name,group_id,group,value} option shape', function () {
        $created = Fixtures::createCampaign($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('campaigns.listAsOptions');

        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray()->not->toBeEmpty();

        foreach ($options as $option) {
            expect($option)->toBeArray();
            expect($option)->toHaveKeys(['id', 'name', 'group_id', 'group', 'value']);
        }

        $matching = array_values(array_filter(
            $options,
            static fn ($o) => (int) $o['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect((int) $matching[0]['value'])->toBe($id);
    });
});

describe('campaigns.show smoke test (legacy fixture, non-regression)', function () {
    test('the historical fixture campaign id=4 ("qbrtcz2") is reachable and responds with id=4', function () {
        $response = $this->api->get('campaigns.show', ['id' => 4]);

        expect($response->getStatusCode())->toBe(200);

        $campaign = ApiClient::json($response);
        expect((int) $campaign['id'])->toBe(4);
    })->group('smoke');
});
