<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;
use Illuminate\Http\UploadedFile;

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

/*
|--------------------------------------------------------------------------
| geoDbs.upload — manual file install (2026-09-03 addition, backlog 3.1)
|--------------------------------------------------------------------------
| ip2location_lite's real path (var/geoip/IP2Location/lite/
| IP2LOCATION-LITE-DB3.BIN) is the SAME path traffic-core's GeoDbResolver
| reads at runtime by default — the fixture file this test uploads is not
| a real .BIN, so it's always deleted again after each test to avoid
| corrupting anything a live traffic-core run might read.
*/
afterEach(function () {
    @unlink(base_path('var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN'));
});

it('installs an uploaded file for a known db type and flips exists/installed/time to real values', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoDbs($admin);

    $file = UploadedFile::fake()->create('IP2LOCATION-LITE-DB3.BIN', 10);

    $response = $this->post(geoDbsEndpoint('upload'), ['id' => 'ip2location_lite', 'file' => $file]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data['exists'])->toBeTrue();
    expect($data['installed'])->toBeTrue();
    expect($data['time'])->not->toBeNull();
    expect(file_exists(base_path('var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN')))->toBeTrue();

    // Real filemtime(), not a fabricated value.
    $expected = date('Y-m-d H:i:s', filemtime(base_path('var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN')));
    expect($data['time'])->toBe($expected);

    // index also reflects it afterwards.
    $index = $this->getJson(geoDbsEndpoint('index'));
    $indexed = collect($index->json())->firstWhere('id', 'ip2location_lite');
    expect($indexed['exists'])->toBeTrue();
});

it('denies upload to a non-admin user with a 403', function () {
    $user = UserFactory::new()->create();
    actingAsForGeoDbs($user);

    $file = UploadedFile::fake()->create('IP2LOCATION-LITE-DB3.BIN', 10);
    $response = $this->post(geoDbsEndpoint('upload'), ['id' => 'ip2location_lite', 'file' => $file]);

    $response->assertStatus(403);
    expect(file_exists(base_path('var/geoip/IP2Location/lite/IP2LOCATION-LITE-DB3.BIN')))->toBeFalse();
});

it('rejects upload for an unknown db id with a 422', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoDbs($admin);

    $file = UploadedFile::fake()->create('whatever.bin', 10);
    $response = $this->post(geoDbsEndpoint('upload'), ['id' => 'not_a_real_db', 'file' => $file]);

    $response->assertStatus(422);
});

it('rejects upload for the internal db type with no installable path', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoDbs($admin);

    $file = UploadedFile::fake()->create('whatever.bin', 10);
    $response = $this->post(geoDbsEndpoint('upload'), ['id' => 'user_bot_ip_db', 'file' => $file]);

    $response->assertStatus(422);
});

it('rejects an upload request with no file', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForGeoDbs($admin);

    $response = $this->post(geoDbsEndpoint('upload'), ['id' => 'ip2location_lite']);

    $response->assertStatus(422);
});
