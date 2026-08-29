<?php

/*
|--------------------------------------------------------------------------
| Groups contract tests
|--------------------------------------------------------------------------
|
| Locks down the `groups` module contract documented in
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md
| (`GroupsController`), run against the backend named by TDS_TEST_TARGET
| (see tests/Support/ApiClient.php).
|
| Every regression test below creates its OWN group fixture via
| `groups.create` (see tests/Support/Fixtures.php) before reading/asserting
| anything - it never depends on a specific pre-existing row. The target
| database is live and mutable (shared with humans clicking around and
| other agents), so pinning assertions to a fixed id is fragile (see the
| equivalent note in CampaignsTest.php).
|
| IMPORTANT, verified live: there is no `groups.show` action -
| `GroupsController` 404s with "Controller action \"showAction\" is not
| defined". So the "create -> show" round-trip for this module is done via
| `groups.index`/`groups.listAsOptions` instead of a dedicated show
| endpoint (see Fixtures::createGroup()).
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

describe('groups.create / groups.index (create -> re-fetch round-trip)', function () {
    test('a freshly created group round-trips via groups.index filtered by its type', function () {
        $created = Fixtures::createGroup($this->api);
        $id = (int) $created['id'];

        expect($created)->toHaveKeys(['id', 'name', 'type', 'position']);
        expect($created['type'])->toBe('campaigns');

        // §10.8: verified live, `groups.index` requires a `type` query param
        // to return anything - calling it with no `type` returns [] even
        // when groups exist (GroupsController filters by type; there is no
        // "all types" mode). See the dedicated `type` test below.
        $response = $this->api->get('groups.index', ['type' => 'campaigns']);
        expect($response->getStatusCode())->toBe(200);

        $groups = ApiClient::json($response);
        expect($groups)->toBeArray()->not->toBeEmpty();

        $matching = array_values(array_filter(
            $groups,
            static fn ($g) => (int) $g['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0]['name'])->toBe($created['name']);
        expect($matching[0]['type'])->toBe('campaigns');
    });

    test('groups.index with no type param returns an empty list even when groups exist', function () {
        // Verified live: this is the actual documented-by-behavior contract,
        // not a bug in this test - GroupsController::indexAction() filters
        // strictly by the `type` query param and has no "all types" default.
        Fixtures::createGroup($this->api);

        $response = $this->api->get('groups.index');
        expect($response->getStatusCode())->toBe(200);

        $groups = ApiClient::json($response);
        expect($groups)->toBe([]);
    });

    test('groups.index?extended=1 adds a count field per group', function () {
        $created = Fixtures::createGroup($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('groups.index', ['type' => 'campaigns', 'extended' => 1]);
        expect($response->getStatusCode())->toBe(200);

        $groups = ApiClient::json($response);
        $matching = array_values(array_filter(
            $groups,
            static fn ($g) => (int) $g['id'] === $id
        ));
        expect($matching)->not->toBeEmpty();
        expect($matching[0])->toHaveKey('count');
    });
});

describe('groups.listAsOptions', function () {
    test('a freshly created group appears with the documented {value,name} option shape', function () {
        $created = Fixtures::createGroup($this->api);
        $id = (int) $created['id'];

        $response = $this->api->get('groups.listAsOptions', ['type' => 'campaigns']);
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

    test('groups.listAsOptions with no type param returns an empty list', function () {
        // Same filter-by-type behavior as groups.index, verified live.
        Fixtures::createGroup($this->api);

        $response = $this->api->get('groups.listAsOptions');
        expect($response->getStatusCode())->toBe(200);

        $options = ApiClient::json($response);
        expect($options)->toBe([]);
    });
});

describe('groups.create validation', function () {
    test('rejects a duplicate name within the same type as HTTP 406', function () {
        $created = Fixtures::createGroup($this->api);

        $response = $this->api->post('groups.create', [], [
            'name' => $created['name'],
            'type' => 'campaigns',
        ]);

        expect($response->getStatusCode())->toBe(406);

        $body = ApiClient::json($response);
        expect($body)->toBeArray();
        expect($body)->toHaveKey('name');
    });
});
