<?php

use App\Models\FavouriteStream;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\StreamFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| FavouriteStreams compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=favouriteStreams.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\FavouriteStreamsController).
|
| `index` returns the current user's favourite streams, fully serialized
| (not just ids); `add`/`remove` take `stream_id` and are gated by
| isEditAllowed() on the stream's parent campaign (§10.2, "FavouriteStreams").
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function favouriteStreamsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "favouriteStreams.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsAdminForFavouriteStreams(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('adds a stream to favourites and lists it on index', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForFavouriteStreams($admin);
    $stream = StreamFactory::new()->create(['name' => 'My Stream']);

    $add = $this->postJson(favouriteStreamsEndpoint('add', ['stream_id' => $stream->id]));
    $add->assertStatus(200);

    $this->assertDatabaseHas('favourite_streams', ['user_id' => $admin->id, 'stream_id' => $stream->id]);

    $index = $this->getJson(favouriteStreamsEndpoint('index'));
    $index->assertStatus(200);
    $data = $index->json();

    expect($data)->toHaveCount(1);
    expect($data[0]['id'])->toBe($stream->id);
    expect($data[0])->toHaveKeys(['filters', 'triggers', 'landings', 'offers']);
});

it('is idempotent on add and removes cleanly', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForFavouriteStreams($admin);
    $stream = StreamFactory::new()->create();

    $this->postJson(favouriteStreamsEndpoint('add', ['stream_id' => $stream->id]))->assertStatus(200);
    $this->postJson(favouriteStreamsEndpoint('add', ['stream_id' => $stream->id]))->assertStatus(200);

    expect(FavouriteStream::where('user_id', $admin->id)->where('stream_id', $stream->id)->count())->toBe(1);

    $this->postJson(favouriteStreamsEndpoint('remove', ['stream_id' => $stream->id]))->assertStatus(200);

    $this->assertDatabaseMissing('favourite_streams', ['user_id' => $admin->id, 'stream_id' => $stream->id]);
});

it('denies a guest (no current user) access to add a favourite with a 403', function () {
    $stream = StreamFactory::new()->create();
    actingAsAdminForFavouriteStreams(null);

    $response = $this->postJson(favouriteStreamsEndpoint('add', ['stream_id' => $stream->id]));

    $response->assertStatus(403);
});
