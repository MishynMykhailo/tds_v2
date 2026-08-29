<?php

use App\Models\Trigger;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\StreamFactory;
use Database\Factories\TriggerFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Triggers compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=triggers.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\TriggersController).
|
| `targets`/`conditions`/`actions` are static reference catalogues (no ACL,
| same as StreamFilters). `update` is the one real write action: it
| replaces ALL of a stream's triggers with the given list — access is
| checked via the stream's parent campaign (§10.2), same pattern as
| StreamsController.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function triggersEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "triggers.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/StreamsTest.php::actingAsAdminForStreams()
 * — duplicated under a distinct name since Pest loads every test file into
 * one process.
 */
function actingAsAdminForTriggers(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForTriggers($admin);
});

it('returns the targets catalogue', function () {
    $response = $this->getJson(triggersEndpoint('targets'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toBeArray();
    expect($data)->toHaveKeys(['stream', 'landings', 'offers', 'selected_page']);
});

it('returns the conditions catalogue', function () {
    $response = $this->getJson(triggersEndpoint('conditions'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['not_respond', 'contains', 'not_contains', 'av_detected', 'always']);
});

it('returns the actions catalogue', function () {
    $response = $this->getJson(triggersEndpoint('actions'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['disable', 'grab_from_page', 'replace_url', 'do_nothing', 'webhook']);
});

it('creates triggers for a stream via triggers.update (id = stream id)', function () {
    $stream = StreamFactory::new()->create();

    $payload = [
        'triggers' => [
            [
                'target' => 'stream',
                'condition' => 'not_respond',
                'action' => 'disable',
                'interval' => 30,
                'enabled' => true,
            ],
        ],
    ];

    $response = $this->postJson(triggersEndpoint('update', ['id' => $stream->id]), $payload);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray()->toHaveCount(1);
    expect($data[0])->toHaveKeys(['id', 'oid', 'stream_id', 'target', 'condition', 'action', 'interval', 'enabled']);
    expect($data[0]['target'])->toBe('stream');

    $this->assertDatabaseHas('triggers', [
        'stream_id' => $stream->id,
        'target' => 'stream',
        'action' => 'disable',
    ]);
});

it('fully replaces a stream\'s triggers on update (old ones not resent are deleted)', function () {
    $stream = StreamFactory::new()->create();
    $kept = TriggerFactory::new()->forStream($stream)->create(['target' => 'stream', 'condition' => 'always', 'action' => 'disable']);
    $dropped = TriggerFactory::new()->forStream($stream)->create(['target' => 'landings', 'condition' => 'always', 'action' => 'disable']);

    $payload = [
        'triggers' => [
            [
                'id' => $kept->id,
                'target' => 'stream',
                'condition' => 'contains',
                'action' => 'disable',
                'interval' => 60,
            ],
        ],
    ];

    $response = $this->postJson(triggersEndpoint('update', ['id' => $stream->id]), $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('triggers', ['id' => $kept->id, 'condition' => 'contains', 'interval' => 60]);
    $this->assertDatabaseMissing('triggers', ['id' => $dropped->id]);
    expect(Trigger::where('stream_id', $stream->id)->count())->toBe(1);
});

it('clears all triggers when an empty list is sent', function () {
    $stream = StreamFactory::new()->create();
    TriggerFactory::new()->forStream($stream)->count(2)->create();

    $response = $this->postJson(triggersEndpoint('update', ['id' => $stream->id]), ['triggers' => []]);

    $response->assertStatus(200);
    expect($response->json())->toBe([]);
    expect(Trigger::where('stream_id', $stream->id)->count())->toBe(0);
});

it('returns a 406 validation error for an invalid target/condition/action', function () {
    $stream = StreamFactory::new()->create();

    $response = $this->postJson(triggersEndpoint('update', ['id' => $stream->id]), [
        'triggers' => [
            ['target' => 'not_a_real_target', 'condition' => 'always', 'action' => 'disable', 'interval' => 30],
        ],
    ]);

    $response->assertStatus(406);
    expect(Trigger::where('stream_id', $stream->id)->count())->toBe(0);
});

it('returns 404 when the stream does not exist', function () {
    $response = $this->postJson(triggersEndpoint('update', ['id' => 999999]), ['triggers' => []]);

    $response->assertStatus(404);
});

it('denies a guest (no current user) access to update triggers with a 403', function () {
    $stream = StreamFactory::new()->create();
    actingAsAdminForTriggers(null);

    $response = $this->postJson(triggersEndpoint('update', ['id' => $stream->id]), ['triggers' => []]);

    $response->assertStatus(403);
});
