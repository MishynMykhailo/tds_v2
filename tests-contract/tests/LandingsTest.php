<?php

/*
|--------------------------------------------------------------------------
| Landings contract tests
|--------------------------------------------------------------------------
|
| Locks down the `landings` module contract documented in
| docs/legacy-reference/frontend/api/10.4_landings.md, run against the
| backend named by TDS_TEST_TARGET (see tests/Support/ApiClient.php).
|
| Every test below creates its OWN landing fixture via `landings.create`
| (see tests/Support/Fixtures.php) before reading/asserting anything - it
| never depends on a specific pre-existing row, since the target database
| is live and shared (see CampaignsTest.php for the full rationale).
|
| §10.4 field shapes below were verified live against TDS_TEST_TARGET
| (not just read off the doc), same as the Fixtures::createLanding() doc
| comment notes for the create response. This suite only covers the
| CRUD-read/list contract (create/show/index/listAsOptions) - the
| LocalFile archive-upload path (`archive` field, download, sandbox
| rendering) documented in §10.4 is out of scope here.
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

// §10.4: LandingSerializer has $_fields = true (all raw model fields pass
// through), verified live via landings.show on a freshly created landing.
const LANDING_RAW_FIELDS = [
    'id', 'name', 'action_payload', 'group_id', 'offer_count', 'state',
    'created_at', 'updated_at', 'landing_type', 'notes', 'action_options',
    'action_type', 'url',
];

describe('landings.create / landings.show', function () {
    test('a freshly created landing round-trips the full documented field set via show', function () {
        $created = Fixtures::createLanding($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('landings.show', ['id' => $id]);
        expect($response->getStatusCode())->toBe(200);

        $landing = ApiClient::json($response);
        expect($landing)->toBeArray();

        // Identity of the fixture this test created.
        expect((int) $landing['id'])->toBe($id);
        expect($landing['name'])->toBe($created['name']);
        expect($landing['state'])->toBe('active');

        foreach (LANDING_RAW_FIELDS as $field) {
            expect($landing)->toHaveKey($field);
        }

        // §10.4: `group` is only added when withGroupName=true; not present
        // on a plain landings.show - verified live.
        expect($landing)->not->toHaveKey('group');

        // §10.4: `preview` is only added when action_type == "local_file";
        // this fixture is a plain (non-local) landing.
        expect($landing)->not->toHaveKey('preview');
    });

    test('rejects a request with no id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('landings.show');

        // §6: NotFoundError -> HTTP 404, body {"error": ..., "stacktrace": ...}.
        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
        expect($body['error'])->toBeString()->not->toBeEmpty();
        expect($body['stacktrace'])->toBeString()->not->toBeEmpty();
    });

    test('rejects a request with a non-existent id as a 404 NotFoundError with a stacktrace body', function () {
        $response = $this->api->get('landings.show', ['id' => 999999999]);

        expect($response->getStatusCode())->toBe(404);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('error');
        expect($body)->toHaveKey('stacktrace');
    });
});

describe('landings.index', function () {
    test('a freshly created landing appears with id/name/state on each element', function () {
        $created = Fixtures::createLanding($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('landings.index');

        expect($response->getStatusCode())->toBe(200);

        $landings = ApiClient::json($response);
        expect($landings)->toBeArray()->not->toBeEmpty();

        foreach ($landings as $landing) {
            expect($landing)->toBeArray();
            expect($landing)->toHaveKey('id');
            expect($landing)->toHaveKey('name');
            expect($landing)->toHaveKey('state');
        }

        $matching = array_values(array_filter(
            $landings,
            static fn ($l) => (int) $l['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect($matching[0]['state'])->toBe('active');
    });
});

describe('landings.listAsOptions', function () {
    test('a freshly created landing appears with the documented {value,name} option shape', function () {
        $created = Fixtures::createLanding($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('landings.listAsOptions');

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
