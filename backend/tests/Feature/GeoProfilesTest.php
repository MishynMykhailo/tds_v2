<?php

use App\Models\GeoProfile;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| GeoProfiles compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=geoprofiles.<action>` route (see
| App\Http\Controllers\Admin\GeoProfilesController). Port fidelity target:
| application/Component/GeoProfiles/{Controller,Service,Serializer,Model}.
|
*/

function geoProfilesEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "geoprofiles.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForGeoProfiles(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoProfiles($admin);
});

it('lists all geo profiles via index', function () {
    GeoProfile::create(['name' => 'USA and Canada', 'countries' => 'US CA']);

    $response = $this->getJson(geoProfilesEndpoint('index'));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['countries'])->toBe(['US', 'CA']);
    expect($data[0]['decorated_countries'])->toBe('United States, Canada');
});

it('listAsOptions returns the same shape as index', function () {
    GeoProfile::create(['name' => 'USA', 'countries' => 'US']);

    $response = $this->getJson(geoProfilesEndpoint('listAsOptions'));

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(1);
});

it('creates a geo profile, joining an array of countries with spaces', function () {
    $response = $this->postJson(geoProfilesEndpoint('create'), [
        'name' => 'West Europe',
        'countries' => ['GB', 'DE', 'FR'],
    ]);

    $response->assertStatus(200);
    $data = $response->json();
    expect($data['countries'])->toBe(['GB', 'DE', 'FR']);

    $this->assertDatabaseHas('country_profiles', ['name' => 'West Europe', 'countries' => 'GB DE FR']);
});

it('denies create to a non-admin with a 403', function () {
    actingAsForGeoProfiles(UserFactory::new()->create());

    $response = $this->postJson(geoProfilesEndpoint('create'), ['name' => 'x', 'countries' => ['US']]);

    $response->assertStatus(403);
});

it('shows a single geo profile by id', function () {
    $profile = GeoProfile::create(['name' => 'USA', 'countries' => 'US']);

    $response = $this->getJson(geoProfilesEndpoint('show', ['id' => $profile->id]));

    $response->assertStatus(200);
    expect($response->json()['name'])->toBe('USA');
});

it('returns 404 for a non-existent id on show/update/delete (not a 200-with-null)', function () {
    // CORRECTION (2026-09-03): a prior version of showAction() claimed
    // legacy returns a literal JSON `null` (200) for a missing id - wrong,
    // verified live against legacy port 8090: GeoProfile::find() throws a
    // real NotFoundError there, same as every other model's static find()
    // in this codebase. deleteAction() used a query-builder delete that
    // silently no-ops for a bad id instead of 404ing too.
    $this->getJson(geoProfilesEndpoint('show', ['id' => 999999]))->assertStatus(404);
    $this->postJson(geoProfilesEndpoint('update', ['id' => 999999]), ['name' => 'x'])->assertStatus(404);
    $this->postJson(geoProfilesEndpoint('delete', ['id' => 999999]))->assertStatus(404);
});

it('updates a geo profile', function () {
    $profile = GeoProfile::create(['name' => 'Old', 'countries' => 'US']);

    $response = $this->postJson(geoProfilesEndpoint('update', ['id' => $profile->id]), [
        'name' => 'New',
        'countries' => ['CA'],
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['id' => $profile->id, 'name' => 'New', 'countries' => ['CA'], 'decorated_countries' => 'Canada']);
});

it('deletes a geo profile', function () {
    $profile = GeoProfile::create(['name' => 'Temp', 'countries' => 'US']);

    $response = $this->postJson(geoProfilesEndpoint('delete', ['id' => $profile->id]));

    $response->assertStatus(200);
    $this->assertDatabaseMissing('country_profiles', ['id' => $profile->id]);
});
