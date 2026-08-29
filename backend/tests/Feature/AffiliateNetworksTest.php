<?php

use App\Models\AffiliateNetwork;
use App\Models\Offer;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\AffiliateNetworkFactory;
use Database\Factories\OfferFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| AffiliateNetworks compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=affiliatenetworks.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\AffiliateNetworksController) through Laravel's
| internal HTTP testing helpers (getJson/postJson) — no external HTTP calls.
|
| The legacy `?object=` key is actually "affiliateNetworks" (confirmed by
| reading `Component\AffiliateNetworks\Initializer::loadControllers()` and
| independently by tests-contract/tests/AffiliateNetworksTest.php's
| docblock) — ObjectDispatchController lowercases it before lookup, so
| "affiliatenetworks" is used here (matches every other multi-word module
| key already registered there). "affiliate_networks" is also registered as
| a no-cost alias — see ObjectDispatchController::CONTROLLERS.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function affiliateNetworksEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "affiliatenetworks.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/TrafficSourcesTest.php::
 * actingAsAdminForTrafficSources() — duplicated under a distinct name
 * since Pest loads every test file into one process.
 */
function actingAsAdminForAffiliateNetworks(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForAffiliateNetworks($admin);
});

it('lists affiliate networks as a JSON array with an offers count', function () {
    AffiliateNetworkFactory::new()->count(3)->create();

    $response = $this->getJson(affiliateNetworksEndpoint('index'));

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray()->and($data)->toHaveCount(3);

    foreach ($data as $network) {
        expect($network)->toHaveKey('offers');
    }
});

it('counts only active offers referencing the network in the index offers field', function () {
    $network = AffiliateNetworkFactory::new()->create();

    OfferFactory::new()->count(2)->create([
        'affiliate_network_id' => $network->id,
        'state' => 'active',
    ]);
    OfferFactory::new()->create([
        'affiliate_network_id' => $network->id,
        'state' => 'archived',
    ]);

    $response = $this->getJson(affiliateNetworksEndpoint('index'));
    $response->assertStatus(200);

    $data = $response->json();
    $match = collect($data)->firstWhere('id', $network->id);

    expect($match)->not->toBeNull();
    expect((int) $match['offers'])->toBe(2);
});

it('shows an affiliate network with every model field, decoding pull_api_options, without an offers key', function () {
    $network = AffiliateNetworkFactory::new()->create([
        'pull_api_options' => json_encode(['url' => 'https://example.com/pull']),
    ]);

    $response = $this->getJson(affiliateNetworksEndpoint('show', ['id' => $network->id]));

    $response->assertStatus(200);

    $data = $response->json();

    foreach ((new AffiliateNetwork)->getFillable() as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');
    expect($data['pull_api_options'])->toBe(['url' => 'https://example.com/pull']);
    expect($data)->not->toHaveKey('offers');
});

it('returns 404 for show without a valid id', function () {
    $response = $this->getJson(affiliateNetworksEndpoint('show'));

    $response->assertStatus(404);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(affiliateNetworksEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates an affiliate network given a valid name', function () {
    $payload = [
        'name' => 'Awesome Affiliates',
        'postback_url' => 'https://example.com/postback',
        'pull_api_options' => ['url' => 'https://example.com/pull'],
    ];

    $response = $this->postJson(affiliateNetworksEndpoint('create'), $payload);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['name'])->toBe('Awesome Affiliates');
    expect($data['pull_api_options'])->toBe(['url' => 'https://example.com/pull']);

    $this->assertDatabaseHas('affiliate_networks', [
        'name' => 'Awesome Affiliates',
        'postback_url' => 'https://example.com/postback',
    ]);
});

it('rejects affiliate network creation without a name with a 406 and a name error', function () {
    $response = $this->postJson(affiliateNetworksEndpoint('create'), [
        'postback_url' => 'https://example.com/missing-name',
    ]);

    $response->assertStatus(406);

    $data = $response->json();
    expect($data)->toHaveKey('name');

    $this->assertDatabaseMissing('affiliate_networks', [
        'postback_url' => 'https://example.com/missing-name',
    ]);
});

it('rejects affiliate network creation with a name over 100 characters', function () {
    $response = $this->postJson(affiliateNetworksEndpoint('create'), [
        'name' => str_repeat('a', 101),
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('name');
});

it('updates an affiliate network', function () {
    $network = AffiliateNetworkFactory::new()->create(['name' => 'Old Name']);

    $response = $this->postJson(affiliateNetworksEndpoint('update', ['id' => $network->id]), [
        'name' => 'Updated Name',
        'offer_param' => 'sub1',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('affiliate_networks', [
        'id' => $network->id,
        'name' => 'Updated Name',
        'offer_param' => 'sub1',
    ]);
});

it('lists affiliate networks as options with a template field', function () {
    AffiliateNetworkFactory::new()->count(2)->create(['template_name' => 'appsflyer']);

    $response = $this->getJson(affiliateNetworksEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(2);

    foreach ($data as $item) {
        expect($item)->toHaveKeys(['id', 'value', 'name', 'template']);
        expect($item['template'])->toBe('appsflyer');
    }
});

it('denies a guest (no current user) access to view an affiliate network with a 403', function () {
    $network = AffiliateNetworkFactory::new()->create();
    actingAsAdminForAffiliateNetworks(null);

    $response = $this->getJson(affiliateNetworksEndpoint('show', ['id' => $network->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create an affiliate network with a 403', function () {
    actingAsAdminForAffiliateNetworks(null);

    $response = $this->postJson(affiliateNetworksEndpoint('create'), [
        'name' => 'Guest Network',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('affiliate_networks', ['name' => 'Guest Network']);
});

it('denies a guest (no current user) access to update an affiliate network with a 403', function () {
    $network = AffiliateNetworkFactory::new()->create(['name' => 'Original']);
    actingAsAdminForAffiliateNetworks(null);

    $response = $this->postJson(affiliateNetworksEndpoint('update', ['id' => $network->id]), [
        'name' => 'Changed',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('affiliate_networks', ['id' => $network->id, 'name' => 'Original']);
});

it('also responds under the affiliate_networks object alias', function () {
    AffiliateNetworkFactory::new()->count(1)->create();

    $response = $this->getJson('/admin/index.php?'.http_build_query(['object' => 'affiliate_networks.index']));

    $response->assertStatus(200);
    expect($response->json())->toBeArray()->and($response->json())->toHaveCount(1);
});
