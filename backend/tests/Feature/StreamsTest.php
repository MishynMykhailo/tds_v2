<?php

use App\Models\Stream;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\StreamFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Streams compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=streams.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and the
| App\Http\Controllers\Admin\StreamsController being ported in parallel)
| through Laravel's internal HTTP testing helpers (getJson/postJson) — no
| external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
| §10.2 (Streams).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function streamsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streams.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/AclTest.php::actingAsForAcl() /
 * tests/Feature/CampaignsTest.php::actingAsAdminForCampaigns() — duplicated
 * under a distinct name since Pest loads every test file into one process
 * (two files can't both declare a global function with the same name).
 * App\Http\Middleware\LegacyAuthMiddleware unconditionally re-derives
 * CurrentUserService from the `states` cookie on every request, so a plain
 * CurrentUserService::set() call would get silently clobbered back to null
 * before the controller runs — mocking AuthService::verifyFromCookie()
 * sidesteps that (and the real cookie/JWT path, exercised elsewhere).
 */
function actingAsAdminForStreams(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

/*
 * AclService is now wired into every ACL-gated action in StreamsController
 * (access is always checked via the stream's parent campaign, see §10.2 and
 * App\Services\AclService::isViewAllowed()/isEditAllowed()). Admins bypass
 * ACL entirely, so authenticating as an admin here keeps every existing
 * assertion in this file valid without needing per-test ACL rule fixtures —
 * same pattern as tests/Feature/CampaignsTest.php.
 */
beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForStreams($admin);
});

it('lists a campaign\'s streams as a JSON array with filters/triggers/landings/offers always present', function () {
    $campaign = CampaignFactory::new()->create();
    StreamFactory::new()->forCampaign($campaign)->count(3)->create();

    // A stream belonging to a *different* campaign must not leak into this
    // campaign's index.
    StreamFactory::new()->count(1)->create();

    $response = $this->getJson(streamsEndpoint('index', ['campaign_id' => $campaign->id]));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(3);

    foreach ($data as $item) {
        expect($item)->toHaveKeys(['id', 'name', 'state']);

        // StreamSerializer::_addAssociation() always adds these, even when
        // empty (see §10.2 — "⚠ починенный баг — раньше терялось при
        // чтении").
        expect($item)->toHaveKeys(['filters', 'triggers', 'landings', 'offers']);
        expect($item['filters'])->toBeArray();
        expect($item['triggers'])->toBeArray();
        expect($item['landings'])->toBeArray();
        expect($item['offers'])->toBeArray();
    }
});

it('shows a stream with every model field', function () {
    $stream = StreamFactory::new()->create();

    $response = $this->getJson(streamsEndpoint('show', ['id' => $stream->id]));

    $response->assertStatus(200);

    $data = $response->json();

    foreach ((new Stream)->getFillable() as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');

    expect($data)->toHaveKeys(['filters', 'triggers', 'landings', 'offers']);
});

it('returns 404 for show without a valid id', function () {
    $response = $this->getJson(streamsEndpoint('show'));

    $response->assertStatus(404);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(streamsEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates a stream given a valid campaign_id and name', function () {
    $campaign = CampaignFactory::new()->create();

    $payload = [
        'campaign_id' => $campaign->id,
        'name' => 'New Stream',
        // action_type/schema are required (mirrors legacy StreamValidator,
        // see StreamsController::validateStreamParams()).
        'action_type' => 'http',
        'schema' => 'landings',
    ];

    $response = $this->postJson(streamsEndpoint('create'), $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('streams', [
        'campaign_id' => $campaign->id,
        'name' => 'New Stream',
    ]);
});

it('creates a stream with nested filters/triggers and returns them non-empty on read', function () {
    $campaign = CampaignFactory::new()->create();

    $payload = [
        'campaign_id' => $campaign->id,
        'name' => 'Stream With Filters',
        'action_type' => 'http',
        'schema' => 'landings',
        'filters' => [
            ['name' => 'country', 'mode' => 'accept', 'payload' => ['US', 'CA']],
        ],
        'triggers' => [
            ['target' => 'stream', 'condition' => 'not_respond', 'action' => 'disable', 'interval' => 30],
        ],
    ];

    $response = $this->postJson(streamsEndpoint('create'), $payload);

    $response->assertStatus(200);

    $data = $response->json();

    expect($data['filters'])->toBeArray()->toHaveCount(1);
    expect($data['filters'][0])->toMatchArray(['name' => 'country', 'mode' => 'accept']);
    expect($data['filters'][0]['payload'])->toBe(['US', 'CA']);

    expect($data['triggers'])->toBeArray()->toHaveCount(1);
    expect($data['triggers'][0])->toMatchArray(['target' => 'stream', 'condition' => 'not_respond', 'action' => 'disable']);

    $this->assertDatabaseHas('stream_filters', ['stream_id' => $data['id'], 'name' => 'country', 'mode' => 'accept']);
    $this->assertDatabaseHas('triggers', ['stream_id' => $data['id'], 'target' => 'stream', 'action' => 'disable']);

    // Re-reading the stream via streams.show must return the same
    // non-empty filters/triggers (§10.2 "⚠ починенный баг — раньше
    // терялось при чтении").
    $show = $this->getJson(streamsEndpoint('show', ['id' => $data['id']]));
    $show->assertStatus(200);
    expect($show->json()['filters'])->toHaveCount(1);
    expect($show->json()['triggers'])->toHaveCount(1);
});

it('replaces nested filters/triggers on update (full replace, not merge)', function () {
    // Explicit 'regular' type: a TYPE_DEFAULT stream force-clears
    // filters/triggers on every save (see StreamsController::
    // updateStreamAssociations()), which would make this test flaky since
    // StreamFactory's default `type` is random.
    $stream = StreamFactory::new()->create(['type' => 'regular']);

    $create = $this->postJson(streamsEndpoint('update', ['id' => $stream->id]), [
        'filters' => [['name' => 'country', 'mode' => 'accept', 'payload' => ['US']]],
        'triggers' => [['target' => 'stream', 'condition' => 'always', 'action' => 'disable', 'interval' => 30]],
    ]);
    $create->assertStatus(200);
    expect($create->json()['filters'])->toHaveCount(1);
    expect($create->json()['triggers'])->toHaveCount(1);

    // Sending a different (single) filter/trigger list must fully replace
    // the previous one, not append to it.
    $update = $this->postJson(streamsEndpoint('update', ['id' => $stream->id]), [
        'filters' => [['name' => 'city', 'mode' => 'reject', 'payload' => ['Kyiv']]],
        'triggers' => [],
    ]);
    $update->assertStatus(200);

    $data = $update->json();
    expect($data['filters'])->toHaveCount(1);
    expect($data['filters'][0]['name'])->toBe('city');
    expect($data['triggers'])->toBe([]);
});

it('updates a stream', function () {
    $stream = StreamFactory::new()->create(['name' => 'Old Name']);

    $response = $this->postJson(streamsEndpoint('update', ['id' => $stream->id]), [
        'name' => 'Updated Name',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('streams', [
        'id' => $stream->id,
        'name' => 'Updated Name',
    ]);
});

it('archives (not physically deletes) a stream via streams.delete', function () {
    $stream = StreamFactory::new()->create(['state' => 'active']);

    // Route param is "delete", not "deleteAction" — the dispatcher appends
    // "Action" itself (object=streams.delete -> StreamsController::deleteAction()).
    // Confirmed live against the legacy backend by the contract-test suite.
    $response = $this->postJson(streamsEndpoint('delete'), ['id' => $stream->id]);

    $response->assertStatus(200);

    // The row must still physically exist (§10.2: "на самом деле
    // архивирует (archiveStream), физического удаления тут нет").
    $this->assertDatabaseHas('streams', ['id' => $stream->id]);

    $stream->refresh();
    expect($stream->state)->not->toBe('active');
    // Confirmed live (both by the StreamsController implementer and the
    // contract-test suite against the legacy backend): archiving sets
    // state to "deleted", not "archived".
    expect($stream->state)->toBe('deleted');
});

it('lists streams as options', function () {
    $campaign = CampaignFactory::new()->create();
    StreamFactory::new()->forCampaign($campaign)->count(2)->create();

    $response = $this->getJson(streamsEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(2);

    foreach ($data as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('name');
    }
});

it('denies a guest (no current user) access to view a stream with a 403', function () {
    $stream = StreamFactory::new()->create();
    actingAsAdminForStreams(null);

    $response = $this->getJson(streamsEndpoint('show', ['id' => $stream->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create a stream with a 403', function () {
    $campaign = CampaignFactory::new()->create();
    actingAsAdminForStreams(null);

    $response = $this->postJson(streamsEndpoint('create'), [
        'campaign_id' => $campaign->id,
        'name' => 'Guest Stream',
        'action_type' => 'http',
        'schema' => 'landings',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseMissing('streams', ['campaign_id' => $campaign->id, 'name' => 'Guest Stream']);
});

it('denies a guest (no current user) access to delete a stream with a 403', function () {
    $stream = StreamFactory::new()->create(['state' => 'active']);
    actingAsAdminForStreams(null);

    $response = $this->postJson(streamsEndpoint('delete'), ['id' => $stream->id]);

    $response->assertStatus(403);
    $stream->refresh();
    expect($stream->state)->toBe('active');
});
