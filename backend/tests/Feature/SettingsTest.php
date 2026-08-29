<?php

use App\Models\Setting;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Settings compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=settings.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\SettingsController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/api/10.12_settings.md,
| cross-checked against
| application/Component/Settings/Controller/SettingsController.php and
| application/Traffic/Settings/Repository/SettingsRepository.php in the old
| codebase.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function settingsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "settings.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForSettings(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('returns all settings as a key => value hash for an admin', function () {
    Setting::query()->create(['key' => 'language', 'value' => 'en']);
    Setting::query()->create(['key' => 'debug', 'value' => '0']);
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->getJson(settingsEndpoint('index'));

    $response->assertStatus(200);
    expect($response->json())->toBe(['language' => 'en', 'debug' => '0']);
});

it('keeps values as raw strings, not coerced booleans/numbers', function () {
    Setting::query()->create(['key' => 'lp_offer_token_ttl', 'value' => '3600']);
    Setting::query()->create(['key' => 'use_cache', 'value' => '1']);
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->getJson(settingsEndpoint('index'));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data['lp_offer_token_ttl'])->toBe('3600');
    expect($data['use_cache'])->toBe('1');
});

it('filters settings by the only param', function () {
    Setting::query()->create(['key' => 'language', 'value' => 'en']);
    Setting::query()->create(['key' => 'debug', 'value' => '0']);
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->getJson(settingsEndpoint('index', ['only' => 'language']));

    $response->assertStatus(200);
    expect($response->json())->toBe(['language' => 'en']);
});

it('filters settings by an array of only keys', function () {
    Setting::query()->create(['key' => 'language', 'value' => 'en']);
    Setting::query()->create(['key' => 'debug', 'value' => '0']);
    Setting::query()->create(['key' => 'api_key', 'value' => 'abc']);
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->get(settingsEndpoint('index').'&'.http_build_query(['only' => ['language', 'debug']]));

    $response->assertStatus(200);
    expect($response->json())->toEqualCanonicalizing(['language' => 'en', 'debug' => '0']);
});

it('denies index to a non-admin user with a 403', function () {
    $user = UserFactory::new()->create();
    actingAsForSettings($user);

    $response = $this->getJson(settingsEndpoint('index'));

    $response->assertStatus(403);
});

it('denies index to a guest with a 403', function () {
    actingAsForSettings(null);

    $response = $this->getJson(settingsEndpoint('index'));

    $response->assertStatus(403);
});

it('updates an existing setting and returns only the updated keys', function () {
    Setting::query()->create(['key' => 'language', 'value' => 'en']);
    Setting::query()->create(['key' => 'debug', 'value' => '0']);
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->postJson(settingsEndpoint('update'), ['language' => 'ru']);

    $response->assertStatus(200);
    expect($response->json())->toBe(['language' => 'ru']);
    $this->assertDatabaseHas('settings', ['key' => 'language', 'value' => 'ru']);
    $this->assertDatabaseHas('settings', ['key' => 'debug', 'value' => '0']);
});

it('creates a new setting row when the key does not exist yet', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->postJson(settingsEndpoint('update'), ['new_key' => 'new_value']);

    $response->assertStatus(200);
    expect($response->json())->toBe(['new_key' => 'new_value']);
    $this->assertDatabaseHas('settings', ['key' => 'new_key', 'value' => 'new_value']);
});

it('updates multiple settings at once', function () {
    Setting::query()->create(['key' => 'a', 'value' => '1']);
    Setting::query()->create(['key' => 'b', 'value' => '2']);
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->postJson(settingsEndpoint('update'), ['a' => '10', 'b' => '20']);

    $response->assertStatus(200);
    expect($response->json())->toBe(['a' => '10', 'b' => '20']);
});

it('rejects a non-post update with a 500', function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsForSettings($admin);

    $response = $this->get(settingsEndpoint('update'));

    $response->assertStatus(500);
});
