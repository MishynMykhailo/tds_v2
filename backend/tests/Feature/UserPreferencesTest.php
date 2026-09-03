<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;
use Database\Factories\UserPreferenceFactory;

/*
|--------------------------------------------------------------------------
| UserPreferences compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=userpreferences.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\UserPreferencesController) through Laravel's
| internal HTTP testing helpers — no external HTTP calls.
|
| Contract reference:
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function userPreferencesEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "userpreferences.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForUserPreferences(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('lists all of the current user\'s preferences', function () {
    $user = UserFactory::new()->create();
    UserPreferenceFactory::new()->forUser($user)->create(['pref_name' => 'language', 'pref_value' => 'en']);
    UserPreferenceFactory::new()->forUser($user)->create(['pref_name' => 'timezone', 'pref_value' => 'UTC']);
    UserPreferenceFactory::new()->create(['pref_name' => 'language', 'pref_value' => 'ru']); // someone else's
    actingAsForUserPreferences($user);

    $response = $this->getJson(userPreferencesEndpoint('index'));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data)->toHaveCount(2);
    foreach ($data as $item) {
        expect($item)->toHaveKey('pref_name');
        expect($item)->toHaveKey('pref_value');
        expect($item)->not->toHaveKey('id');
    }
});

it('denies index to a guest with a 403', function () {
    actingAsForUserPreferences(null);

    $response = $this->getJson(userPreferencesEndpoint('index'));

    $response->assertStatus(403);
});

it('gets a single preference value by pref_name', function () {
    $user = UserFactory::new()->create();
    UserPreferenceFactory::new()->forUser($user)->create(['pref_name' => 'language', 'pref_value' => 'en']);
    actingAsForUserPreferences($user);

    $response = $this->getJson(userPreferencesEndpoint('get', ['pref_name' => 'language']));

    $response->assertStatus(200);
    // Raw, unquoted body per the real legacy contract (verified live
    // against legacy port 8090, see tests-contract/tests/
    // UserPreferencesTest.php) — NOT JSON-encoded, so asserted via
    // ->getContent() rather than ->json().
    expect($response->getContent())->toBe('en');
});

it('gets an empty body for a preference that does not exist', function () {
    $user = UserFactory::new()->create();
    actingAsForUserPreferences($user);

    $response = $this->getJson(userPreferencesEndpoint('get', ['pref_name' => 'nope']));

    $response->assertStatus(200);
    // Legacy: HTTP 200 with an EMPTY body for an unset pref_name, not a
    // JSON `null` — verified live against legacy port 8090.
    expect($response->getContent())->toBe('');
});

it('sets a new preference', function () {
    $user = UserFactory::new()->create();
    actingAsForUserPreferences($user);

    $response = $this->postJson(userPreferencesEndpoint('set'), [
        'pref_name' => 'timezone',
        'pref_value' => 'Europe/Kyiv',
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['pref_name' => 'timezone', 'pref_value' => 'Europe/Kyiv']);

    $this->assertDatabaseHas('user_preferences', [
        'user_id' => $user->id,
        'pref_name' => 'timezone',
        'pref_value' => 'Europe/Kyiv',
    ]);
});

it('overwrites an existing preference rather than duplicating it', function () {
    $user = UserFactory::new()->create();
    UserPreferenceFactory::new()->forUser($user)->create(['pref_name' => 'timezone', 'pref_value' => 'UTC']);
    actingAsForUserPreferences($user);

    $response = $this->postJson(userPreferencesEndpoint('set'), [
        'pref_name' => 'timezone',
        'pref_value' => 'America/New_York',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseCount('user_preferences', 1);
    $this->assertDatabaseHas('user_preferences', [
        'user_id' => $user->id,
        'pref_name' => 'timezone',
        'pref_value' => 'America/New_York',
    ]);
});

it('rejects set without a pref_name with a 406', function () {
    $user = UserFactory::new()->create();
    actingAsForUserPreferences($user);

    $response = $this->postJson(userPreferencesEndpoint('set'), [
        'pref_value' => 'whatever',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('pref_name');
});

it('denies set to a guest with a 403', function () {
    actingAsForUserPreferences(null);

    $response = $this->postJson(userPreferencesEndpoint('set'), [
        'pref_name' => 'timezone',
        'pref_value' => 'UTC',
    ]);

    $response->assertStatus(403);
});
