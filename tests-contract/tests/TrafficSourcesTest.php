<?php

/*
|--------------------------------------------------------------------------
| TrafficSources contract tests
|--------------------------------------------------------------------------
|
| Locks down the `trafficSources` module contract documented in
| docs/legacy-reference/frontend/api/10.6_trafficsources.md, run against
| the backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Every regression test below creates its OWN traffic source fixture via
| `trafficSources.create` (see tests/Support/Fixtures.php) before reading/
| asserting anything - it never depends on a specific pre-existing row.
| The target database is live and mutable (shared with humans clicking
| around and other agents), so pinning assertions to a fixed id is
| fragile (see the equivalent note in CampaignsTest.php).
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

// §10.6: TrafficSourceSerializer has $_fields = true (all raw model fields
// pass through, no extra()). Verified live against the `tds_traffic_sources`
// table schema.
const TRAFFIC_SOURCE_RAW_FIELDS = [
    'id', 'name', 'postback_url', 'postback_statuses', 'template_name',
    'accept_parameters', 'parameters', 'state', 'created_at', 'updated_at',
    'notes', 'traffic_loss',
];

describe('trafficSources.create / trafficSources.show', function () {
    test('a freshly created traffic source round-trips the full documented field set via show', function () {
        $created = Fixtures::createTrafficSource($this->api);
        $id = (int) $created['id'];

        // §10.6, verified live: trafficSources.create's response body only
        // contains {name, created_at, updated_at, state, id} - the same
        // partial-response behavior as offers.create/landings.create, unlike
        // campaigns.create's full field set. See Fixtures::createTrafficSource().
        expect($created)->toHaveKeys(['id', 'name', 'created_at', 'updated_at', 'state']);

        $response = $this->api->get('trafficSources.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $source = ApiClient::json($response);
        expect($source)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $source['id'])->toBe($id);
        expect($source['name'])->toBe($created['name']);
        expect($source['state'])->toBe('active');

        foreach (TRAFFIC_SOURCE_RAW_FIELDS as $field) {
            expect($source)->toHaveKey($field);
        }
    });

    test('rejects a request with no id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('trafficSources.show');

        // §6: NotFoundError -> HTTP 404, body {"error": ..., "stacktrace": ...}.
        // Verified live: trafficSources.show follows the same §6 contract as
        // campaigns.show (unlike domains.show - see DomainsTest.php).
        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
        expect($body['error'])->toBeString()->not->toBeEmpty();
        expect($body['stacktrace'])->toBeString()->not->toBeEmpty();
    });

    test('rejects a request with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('trafficSources.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('trafficSources.index', function () {
    test('a freshly created traffic source appears with id/name/state on each element', function () {
        $created = Fixtures::createTrafficSource($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('trafficSources.index');

        expect($response->getStatusCode())->toBe(200);

        $sources = ApiClient::json($response);
        expect($sources)->toBeArray()->not->toBeEmpty();

        foreach ($sources as $source) {
            expect($source)->toBeArray();
            expect($source)->toHaveKey('id');
            expect($source)->toHaveKey('name');
            expect($source)->toHaveKey('state');
        }

        $matching = array_values(array_filter(
            $sources,
            static fn ($s) => (int) $s['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect($matching[0]['state'])->toBe('active');
    });
});

describe('trafficSources.listAsOptions', function () {
    test('a freshly created traffic source appears with the documented {value,name} option shape', function () {
        // §10.6, verified live: unlike campaigns.listAsOptions
        // ({id,name,group_id,group,value}), TrafficSourceRepository::listAsOptions()
        // delegates to the generic Core\Entity\ListOptions\Builder, which only
        // emits {value, name} - there is no `id` key here.
        $created = Fixtures::createTrafficSource($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('trafficSources.listAsOptions');

        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBeArray()->not->toBeEmpty();

        foreach ($options as $option) {
            expect($option)->toBeArray();
            expect($option)->toHaveKeys(['value', 'name']);
        }

        $matching = array_values(array_filter(
            $options,
            static fn ($o) => (int) $o['value'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
    });
});
