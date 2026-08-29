<?php

use App\Models\StreamEvent;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\StreamFactory;
use Database\Factories\TriggerFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| StreamEvents compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=streamEvents.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\StreamEventsController). Table is
| `monitoring_history`, NOT `stream_events` (App\Models\StreamEvent).
|
| `index` (`stream_id`, `limit`, `page`) has a side effect: unread events on
| the returned page are marked read (§10.2, "StreamEvents"). Both `index`
| and `clear` are gated by isViewAllowed() on the stream's parent campaign.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function streamEventsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "streamEvents.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForStreamEvents(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    actingAsAdminForStreamEvents(UserFactory::new()->admin()->create());
});

it('lists events and marks unread events as read as a side effect', function () {
    $stream = StreamFactory::new()->create();
    $trigger = TriggerFactory::new()->forStream($stream)->create();

    $event = StreamEvent::create([
        'level' => StreamEvent::INFO,
        'stream_id' => $stream->id,
        'trigger_id' => $trigger->id,
        'message' => 'Trigger fired',
        'date' => now(),
        'state' => StreamEvent::UNREAD,
    ]);

    $response = $this->getJson(streamEventsEndpoint('index', ['stream_id' => $stream->id]));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data['total'])->toBe('1');
    expect($data['items'])->toHaveCount(1);
    expect($data['items'][0]['state'])->toBe(StreamEvent::READ);

    $this->assertDatabaseHas('monitoring_history', ['id' => $event->id, 'state' => StreamEvent::READ]);
});

it('paginates with limit/page, newest first', function () {
    $stream = StreamFactory::new()->create();
    $trigger = TriggerFactory::new()->forStream($stream)->create();

    foreach (range(1, 3) as $i) {
        StreamEvent::create([
            'level' => StreamEvent::INFO,
            'stream_id' => $stream->id,
            'trigger_id' => $trigger->id,
            'message' => "Event {$i}",
            'date' => now(),
            'state' => StreamEvent::READ,
        ]);
    }

    $response = $this->getJson(streamEventsEndpoint('index', ['stream_id' => $stream->id, 'limit' => 2, 'page' => 1]));

    $data = $response->json();
    expect($data['total'])->toBe('3');
    expect($data['items'])->toHaveCount(2);
    expect($data['items'][0]['message'])->toBe('Event 3');
});

it('clears all events for a stream', function () {
    $stream = StreamFactory::new()->create();
    $trigger = TriggerFactory::new()->forStream($stream)->create();
    StreamEvent::create([
        'level' => StreamEvent::WARNING,
        'stream_id' => $stream->id,
        'trigger_id' => $trigger->id,
        'message' => 'Something happened',
        'date' => now(),
        'state' => StreamEvent::UNREAD,
    ]);

    $response = $this->postJson(streamEventsEndpoint('clear', ['stream_id' => $stream->id]));

    $response->assertStatus(200);
    expect(StreamEvent::where('stream_id', $stream->id)->count())->toBe(0);
});

it('denies a guest (no current user) access to view stream events with a 403', function () {
    $stream = StreamFactory::new()->create();
    actingAsAdminForStreamEvents(null);

    $response = $this->getJson(streamEventsEndpoint('index', ['stream_id' => $stream->id]));

    $response->assertStatus(403);
});
