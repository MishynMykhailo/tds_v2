<?php

use App\Models\TrafficSource;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\TrafficSourceFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| TrafficSources compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=trafficsources.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\TrafficSourcesController) through Laravel's
| internal HTTP testing helpers (getJson/postJson) — no external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/api/10.6_trafficsources.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function trafficSourcesEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "trafficsources.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/OffersTest.php::actingAsAdminForOffers()
 * / tests/Feature/LandingsTest.php::actingAsAdminForLandings() — duplicated
 * under a distinct name since Pest loads every test file into one process.
 */
function actingAsAdminForTrafficSources(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForTrafficSources($admin);
});

it('lists traffic sources as a JSON array', function () {
    TrafficSourceFactory::new()->count(3)->create();

    $response = $this->getJson(trafficSourcesEndpoint('index'));

    $response->assertStatus(200);

    expect($response->json())->toBeArray()->and($response->json())->toHaveCount(3);
});

it('shows a traffic source with every model field, decoding JSON fields', function () {
    $source = TrafficSourceFactory::new()->create([
        'parameters' => json_encode(['sub_id_1' => ['alias' => 'Sub1']]),
    ]);

    $response = $this->getJson(trafficSourcesEndpoint('show', ['id' => $source->id]));

    $response->assertStatus(200);

    $data = $response->json();

    foreach ((new TrafficSource)->getFillable() as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');
    expect($data['parameters'])->toBe(['sub_id_1' => ['alias' => 'Sub1']]);
    expect($data['postback_statuses'])->toBe(['sale', 'lead', 'rejected', 'rebill']);
});

it('returns 404 for show without a valid id', function () {
    $response = $this->getJson(trafficSourcesEndpoint('show'));

    $response->assertStatus(404);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(trafficSourcesEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates a traffic source given a valid name', function () {
    $payload = [
        'name' => 'Facebook Ads',
        'postback_url' => 'https://example.com/postback',
        'parameters' => ['sub_id' => ['alias' => 'Sub']],
    ];

    $response = $this->postJson(trafficSourcesEndpoint('create'), $payload);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['name'])->toBe('Facebook Ads');
    expect($data['parameters'])->toBe(['sub_id' => ['alias' => 'Sub']]);

    $this->assertDatabaseHas('traffic_sources', [
        'name' => 'Facebook Ads',
        'postback_url' => 'https://example.com/postback',
    ]);
});

it('rejects traffic source creation without a name with a 406 and a name error', function () {
    $response = $this->postJson(trafficSourcesEndpoint('create'), [
        'postback_url' => 'https://example.com/missing-name',
    ]);

    $response->assertStatus(406);

    $data = $response->json();
    expect($data)->toHaveKey('name');

    $this->assertDatabaseMissing('traffic_sources', [
        'postback_url' => 'https://example.com/missing-name',
    ]);
});

it('rejects traffic source creation with a name over 50 characters', function () {
    $response = $this->postJson(trafficSourcesEndpoint('create'), [
        'name' => str_repeat('a', 51),
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('name');
});

it('updates a traffic source', function () {
    $source = TrafficSourceFactory::new()->create(['name' => 'Old Name']);

    $response = $this->postJson(trafficSourcesEndpoint('update', ['id' => $source->id]), [
        'name' => 'Updated Name',
        'traffic_loss' => 12.5,
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('traffic_sources', [
        'id' => $source->id,
        'name' => 'Updated Name',
    ]);
});

it('lists traffic sources as options', function () {
    TrafficSourceFactory::new()->count(2)->create();

    $response = $this->getJson(trafficSourcesEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(2);

    foreach ($data as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('name');
    }
});

it('denies a guest (no current user) access to view a traffic source with a 403', function () {
    $source = TrafficSourceFactory::new()->create();
    actingAsAdminForTrafficSources(null);

    $response = $this->getJson(trafficSourcesEndpoint('show', ['id' => $source->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create a traffic source with a 403', function () {
    actingAsAdminForTrafficSources(null);

    $response = $this->postJson(trafficSourcesEndpoint('create'), [
        'name' => 'Guest Source',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('traffic_sources', ['name' => 'Guest Source']);
});

it('denies a guest (no current user) access to update a traffic source with a 403', function () {
    $source = TrafficSourceFactory::new()->create(['name' => 'Original']);
    actingAsAdminForTrafficSources(null);

    $response = $this->postJson(trafficSourcesEndpoint('update', ['id' => $source->id]), [
        'name' => 'Changed',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('traffic_sources', ['id' => $source->id, 'name' => 'Original']);
});
