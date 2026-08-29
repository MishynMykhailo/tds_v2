<?php

use App\Models\Landing;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\LandingFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Landings compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=landings.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and the
| App\Http\Controllers\Admin\LandingsController being ported in parallel)
| through Laravel's internal HTTP testing helpers (getJson/postJson) — no
| external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
| §10.4 (Landings).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
| NOTE: as of writing, LandingsController.php is being written by a parallel
| agent and 'landings' is not yet registered in
| ObjectDispatchController::CONTROLLERS — every request below may 404 until
| both land. That is expected, not a bug in this test file.
|
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function landingsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "landings.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/CampaignsTest.php::actingAsAdminForCampaigns()
 * / tests/Feature/StreamsTest.php::actingAsAdminForStreams() /
 * tests/Feature/OffersTest.php::actingAsAdminForOffers() — duplicated under
 * a distinct name since Pest loads every test file into one process (two
 * files can't both declare a global function with the same name).
 * App\Http\Middleware\LegacyAuthMiddleware unconditionally re-derives
 * CurrentUserService from the `states` cookie on every request, so a plain
 * CurrentUserService::set() call would get silently clobbered back to null
 * before the controller runs — mocking AuthService::verifyFromCookie()
 * sidesteps that (and the real cookie/JWT path, exercised elsewhere).
 */
function actingAsAdminForLandings(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

/*
 * Admins bypass ACL entirely (User::isAdmin() === true short-circuits every
 * AclService check per §5), so authenticating as an admin here keeps every
 * assertion in this file valid without needing per-test ACL rule fixtures
 * — same pattern as CampaignsTest.php/StreamsTest.php/OffersTest.php, even
 * though 'landings' is not yet wired into AclService::ACL_KEYS.
 */
beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForLandings($admin);
});

it('lists landings as a JSON array', function () {
    LandingFactory::new()->count(3)->create();

    $response = $this->getJson(landingsEndpoint('index'));

    $response->assertStatus(200);

    expect($response->json())->toBeArray()->and($response->json())->toHaveCount(3);
});

it('shows a landing with every model field', function () {
    $landing = LandingFactory::new()->create();

    $response = $this->getJson(landingsEndpoint('show', ['id' => $landing->id]));

    $response->assertStatus(200);

    $data = $response->json();

    foreach ((new Landing)->getFillable() as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');
});

it('returns 404 for show without a valid id', function () {
    $response = $this->getJson(landingsEndpoint('show'));

    $response->assertStatus(404);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(landingsEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates a landing given a valid name', function () {
    $payload = [
        'name' => 'Summer Landing',
        'landing_type' => 'external',
        'action_type' => 'http',
        'url' => 'https://example.com/landing',
    ];

    $response = $this->postJson(landingsEndpoint('create'), $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('landings', [
        'name' => 'Summer Landing',
        'landing_type' => 'external',
    ]);
});

it('rejects landing creation without a name with a 406 and a name error', function () {
    $response = $this->postJson(landingsEndpoint('create'), [
        'url' => 'https://example.com/missing-name',
    ]);

    $response->assertStatus(406);

    $data = $response->json();
    expect($data)->toHaveKey('name');

    $this->assertDatabaseMissing('landings', [
        'url' => 'https://example.com/missing-name',
    ]);
});

it('updates a landing', function () {
    $landing = LandingFactory::new()->create(['name' => 'Old Name']);

    $response = $this->postJson(landingsEndpoint('update', ['id' => $landing->id]), [
        'name' => 'Updated Name',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('landings', [
        'id' => $landing->id,
        'name' => 'Updated Name',
    ]);
});

it('lists landings as options', function () {
    LandingFactory::new()->count(2)->create();

    $response = $this->getJson(landingsEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(2);

    foreach ($data as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('name');
    }
});

it('denies a guest (no current user) access to view a landing with a 403', function () {
    $landing = LandingFactory::new()->create();
    actingAsAdminForLandings(null);

    $response = $this->getJson(landingsEndpoint('show', ['id' => $landing->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create a landing with a 403', function () {
    actingAsAdminForLandings(null);

    $response = $this->postJson(landingsEndpoint('create'), [
        'name' => 'Guest Landing',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('landings', ['name' => 'Guest Landing']);
});

it('denies a guest (no current user) access to update a landing with a 403', function () {
    $landing = LandingFactory::new()->create(['name' => 'Original']);
    actingAsAdminForLandings(null);

    $response = $this->postJson(landingsEndpoint('update', ['id' => $landing->id]), [
        'name' => 'Changed',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('landings', ['id' => $landing->id, 'name' => 'Original']);
});
