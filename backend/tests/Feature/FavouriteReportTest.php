<?php

use App\Models\FavouriteReport;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| FavouriteReport compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=favouriteReports.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\FavouriteReportController). No ACL/campaign
| check anywhere — access is purely per-row ownership via
| CurrentUserService::get(), same as the legacy `findByUser()` scoping.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function favouriteReportsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "favouriteReports.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/StreamsTest.php::actingAsAdminForStreams()
 * — duplicated under a distinct name since Pest loads every test file into
 * one process.
 */
function actingAsUserForFavouriteReports(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('lists only the current user\'s favourite reports, ordered by name', function () {
    $user = UserFactory::new()->create();
    $other = UserFactory::new()->create();
    actingAsUserForFavouriteReports($user);

    FavouriteReport::create(['name' => 'Zebra', 'user_id' => $user->id, 'payload' => '{"a":1}']);
    FavouriteReport::create(['name' => 'Apple', 'user_id' => $user->id, 'payload' => '{"b":2}']);
    FavouriteReport::create(['name' => 'Not mine', 'user_id' => $other->id, 'payload' => '{}']);

    $response = $this->getJson(favouriteReportsEndpoint('index'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toBeArray()->toHaveCount(2);
    expect(array_column($data, 'name'))->toBe(['Apple', 'Zebra']);
    foreach ($data as $entry) {
        expect($entry)->not->toHaveKey('user_id');
        expect($entry)->toHaveKeys(['id', 'name', 'is_shared', 'payload']);
    }
});

it('returns an empty list for a guest', function () {
    actingAsUserForFavouriteReports(null);

    $response = $this->getJson(favouriteReportsEndpoint('index'));

    $response->assertStatus(200);
    expect($response->json())->toBe([]);
});

it('creates a favourite report for the current user', function () {
    $user = UserFactory::new()->create();
    actingAsUserForFavouriteReports($user);

    $response = $this->postJson(favouriteReportsEndpoint('create'), [
        'name' => 'My report',
        'payload' => '{"columns":["clicks"]}',
        'is_shared' => true,
    ]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data['name'])->toBe('My report');
    expect($data['is_shared'])->toBeTrue();
    expect($data['payload'])->toBe('{"columns":["clicks"]}');
    expect($data)->not->toHaveKey('user_id');

    $this->assertDatabaseHas('favourite_reports', [
        'name' => 'My report', 'user_id' => $user->id, 'is_shared' => 1,
    ]);
});

it('rejects create with a missing name or payload', function () {
    $user = UserFactory::new()->create();
    actingAsUserForFavouriteReports($user);

    $response = $this->postJson(favouriteReportsEndpoint('create'), ['payload' => '{}']);
    $response->assertStatus(406);

    $response = $this->postJson(favouriteReportsEndpoint('create'), ['name' => 'No payload']);
    $response->assertStatus(406);
});

it('denies create for a guest with a 403', function () {
    actingAsUserForFavouriteReports(null);

    $response = $this->postJson(favouriteReportsEndpoint('create'), ['name' => 'X', 'payload' => '{}']);

    $response->assertStatus(403);
});

it('updates only the current user\'s own favourite report', function () {
    $user = UserFactory::new()->create();
    actingAsUserForFavouriteReports($user);
    $report = FavouriteReport::create(['name' => 'Old name', 'user_id' => $user->id, 'payload' => '{}']);

    $response = $this->postJson(favouriteReportsEndpoint('update', ['id' => $report->id]), [
        'name' => 'New name',
        'payload' => '{"x":1}',
    ]);

    $response->assertStatus(200);
    expect($response->json()['name'])->toBe('New name');

    $this->assertDatabaseHas('favourite_reports', ['id' => $report->id, 'name' => 'New name']);
});

it('returns 404 updating a report that does not exist', function () {
    $user = UserFactory::new()->create();
    actingAsUserForFavouriteReports($user);

    $response = $this->postJson(favouriteReportsEndpoint('update', ['id' => 999999]), ['name' => 'X']);

    $response->assertStatus(404);
});

it('returns 404 updating another user\'s report', function () {
    $owner = UserFactory::new()->create();
    $intruder = UserFactory::new()->create();
    $report = FavouriteReport::create(['name' => 'Owner report', 'user_id' => $owner->id, 'payload' => '{}']);

    actingAsUserForFavouriteReports($intruder);
    $response = $this->postJson(favouriteReportsEndpoint('update', ['id' => $report->id]), ['name' => 'Hacked']);

    $response->assertStatus(404);
    $this->assertDatabaseHas('favourite_reports', ['id' => $report->id, 'name' => 'Owner report']);
});

it('deletes only the current user\'s own favourite report', function () {
    $user = UserFactory::new()->create();
    actingAsUserForFavouriteReports($user);
    $report = FavouriteReport::create(['name' => 'To delete', 'user_id' => $user->id, 'payload' => '{}']);

    $response = $this->postJson(favouriteReportsEndpoint('delete', ['id' => $report->id]));

    $response->assertStatus(200);
    $this->assertDatabaseMissing('favourite_reports', ['id' => $report->id]);
});

it('returns 404 deleting another user\'s report and leaves it intact', function () {
    $owner = UserFactory::new()->create();
    $intruder = UserFactory::new()->create();
    $report = FavouriteReport::create(['name' => 'Owner report', 'user_id' => $owner->id, 'payload' => '{}']);

    actingAsUserForFavouriteReports($intruder);
    $response = $this->postJson(favouriteReportsEndpoint('delete', ['id' => $report->id]));

    $response->assertStatus(404);
    $this->assertDatabaseHas('favourite_reports', ['id' => $report->id]);
});
