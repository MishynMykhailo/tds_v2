<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| GeoDbs compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=geoDbs.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\GeoDbsController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Scope: only the static status-listing (index), the data_type => db_id
| settings map (settings/saveSettings), and the update TODO-stub (501) are
| covered — real file download is explicitly out of scope for this
| controller (see its class docblock).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

function geoDbsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "geoDbs.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForGeoDbs(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('lists all 15 known geo db types', function () {
    actingAsForGeoDbs(null);

    $response = $this->getJson(geoDbsEndpoint('index'));

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(15);
});

it('honestly reports every geo db as not installed since none are on disk', function () {
    actingAsForGeoDbs(null);

    $response = $this->getJson(geoDbsEndpoint('index'));

    $response->assertStatus(200);
    $items = $response->json();

    foreach ($items as $item) {
        expect($item['exists'])->toBeFalse()
            ->and($item['installed'])->toBeFalse()
            ->and($item['time'])->toBeNull();
    }
});

it('includes the expected metadata fields for a known hosted db', function () {
    actingAsForGeoDbs(null);

    $response = $this->getJson(geoDbsEndpoint('index'));

    $response->assertStatus(200);
    $items = collect($response->json());
    $ip2locationLite = $items->firstWhere('id', 'ip2location_lite');

    expect($ip2locationLite)->not->toBeNull();
    expect($ip2locationLite)->toMatchArray([
        'id' => 'ip2location_lite',
        'name' => 'IP2Location DB3 Lite',
        'type' => 'hosted',
        'exists' => false,
        'status_code' => 'ok',
        'status_text' => '',
        'is_recommended' => null,
        'setting_key' => null,
        'purchase_link' => null,
        'key' => null,
    ]);
    expect($ip2locationLite['data_types'])->toBe(['country', 'region', 'city']);
});

it('reports no_key status for an external db with no configured token', function () {
    actingAsForGeoDbs(null);

    $response = $this->getJson(geoDbsEndpoint('index'));

    $response->assertStatus(200);
    $items = collect($response->json());
    $ip2locationFullIsp = $items->firstWhere('id', 'ip2location_full_isp');

    expect($ip2locationFullIsp)->toMatchArray([
        'type' => 'external',
        'status_code' => 'no_key',
        'is_recommended' => true,
        'setting_key' => 'ip2location_full_isp_token',
        'purchase_link' => 'https://tds.io/go/iptolocation',
        'key' => null,
    ]);
});

it('reflects a configured token as the key field for an external db', function () {
    Setting::query()->create(['key' => 'proip_essential_key', 'value' => 'my-token']);
    actingAsForGeoDbs(null);

    $response = $this->getJson(geoDbsEndpoint('index'));

    $response->assertStatus(200);
    $proip = collect($response->json())->firstWhere('id', 'proip_essential');

    expect($proip['key'])->toBe('my-token');
    expect($proip['status_code'])->toBe('ok');
});

it('returns an empty settings map by default', function () {
    actingAsForGeoDbs(null);

    $response = $this->getJson(geoDbsEndpoint('settings'));

    $response->assertStatus(200);
    expect($response->json())->toBe([]);
});

it('round-trips settings through saveSettings and settings', function () {
    actingAsForGeoDbs(null);

    $save = $this->postJson(geoDbsEndpoint('saveSettings'), [
        'settings' => ['country' => 'ip2location_lite', 'isp' => 'maxmind_isp'],
    ]);

    $save->assertStatus(200);
    expect($save->json())->toBe(['country' => 'ip2location_lite', 'isp' => 'maxmind_isp']);

    $read = $this->getJson(geoDbsEndpoint('settings'));
    $read->assertStatus(200);
    expect($read->json())->toBe(['country' => 'ip2location_lite', 'isp' => 'maxmind_isp']);

    $this->assertDatabaseHas('settings', [
        'key' => 'ipdb',
        'value' => json_encode(['country' => 'ip2location_lite', 'isp' => 'maxmind_isp']),
    ]);
});

it('overwrites previously saved settings on a second saveSettings call', function () {
    actingAsForGeoDbs(null);

    $this->postJson(geoDbsEndpoint('saveSettings'), ['settings' => ['country' => 'sypex_lite']]);
    $second = $this->postJson(geoDbsEndpoint('saveSettings'), ['settings' => ['country' => 'maxmind_lite']]);

    $second->assertStatus(200);
    expect($second->json())->toBe(['country' => 'maxmind_lite']);
});

it('rejects a non-array settings payload with a 500', function () {
    actingAsForGeoDbs(null);

    $response = $this->postJson(geoDbsEndpoint('saveSettings'), ['settings' => 'not-an-array']);

    $response->assertStatus(500);
});

it('returns 501 not implemented for update, even for an admin', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoDbs($admin);

    $response = $this->postJson(geoDbsEndpoint('update'), ['id' => 'ip2location_lite']);

    $response->assertStatus(501);
    expect($response->json('error'))->toContain('not implemented');
});

it('denies update to a non-admin user with a 403', function () {
    $user = UserFactory::new()->create();
    actingAsForGeoDbs($user);

    $response = $this->postJson(geoDbsEndpoint('update'), ['id' => 'ip2location_lite']);

    $response->assertStatus(403);
});

it('denies update to a guest with a 403', function () {
    actingAsForGeoDbs(null);

    $response = $this->postJson(geoDbsEndpoint('update'), ['id' => 'ip2location_lite']);

    $response->assertStatus(403);
});

it('returns a 500 for update with an unknown db id', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoDbs($admin);

    $response = $this->postJson(geoDbsEndpoint('update'), ['id' => 'not_a_real_db']);

    $response->assertStatus(500);
});
