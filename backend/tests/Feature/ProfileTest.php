<?php

use App\Models\User;
use App\Models\UserPreference;
use App\Services\AuthService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Profile compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=profile.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\ProfileController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Unlike Users/Groups/ApiKeys, ProfileController operates ONLY on
| CurrentUserService::get() — there is no isAdmin() gate anywhere, and no
| id/userId param support at all.
|
| Contract reference:
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function profileEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "profile.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForProfile(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('returns the current user\'s own data, never the password hash', function () {
    $user = UserFactory::new()->create(['login' => 'selfuser']);
    actingAsForProfile($user);

    $response = $this->getJson(profileEndpoint('index'));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data['login'])->toBe('selfuser');
    expect($data)->not->toHaveKey('password_hash');
    expect($data)->toHaveKey('preferences');
});

it('denies index to a guest with a 403', function () {
    actingAsForProfile(null);

    $response = $this->getJson(profileEndpoint('index'));

    $response->assertStatus(403);
});

it('updates own preferences without touching the password', function () {
    $user = UserFactory::new()->create();
    $originalHash = $user->password_hash;
    actingAsForProfile($user);

    $response = $this->postJson(profileEndpoint('update'), [
        'preferences' => ['language' => 'en', 'timezone' => 'Europe/Kyiv'],
    ]);

    $response->assertStatus(200);

    $user->refresh();
    expect($user->password_hash)->toBe($originalHash);

    expect(UserPreference::where('user_id', $user->id)->where('pref_name', 'language')->value('pref_value'))->toBe('en');
    expect(UserPreference::where('user_id', $user->id)->where('pref_name', 'timezone')->value('pref_value'))->toBe('Europe/Kyiv');
});

it('changes the password when current_password is correct and confirmation matches', function () {
    $user = UserFactory::new()->create(['password_hash' => Hash::make('oldpass')]);
    actingAsForProfile($user);

    $response = $this->postJson(profileEndpoint('update'), [
        'current_password' => 'oldpass',
        'new_password' => 'newpass123',
        'new_password_confirmation' => 'newpass123',
    ]);

    $response->assertStatus(200);

    $user->refresh();
    expect(Hash::check('newpass123', $user->password_hash))->toBeTrue();
});

it('rejects a password change with an incorrect current_password with a 406', function () {
    $user = UserFactory::new()->create(['password_hash' => Hash::make('oldpass')]);
    actingAsForProfile($user);

    $response = $this->postJson(profileEndpoint('update'), [
        'current_password' => 'wrongpass',
        'new_password' => 'newpass123',
        'new_password_confirmation' => 'newpass123',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('current_password');

    $user->refresh();
    expect(Hash::check('oldpass', $user->password_hash))->toBeTrue();
});

it('rejects a password change with a mismatched confirmation with a 406', function () {
    $user = UserFactory::new()->create(['password_hash' => Hash::make('oldpass')]);
    actingAsForProfile($user);

    $response = $this->postJson(profileEndpoint('update'), [
        'current_password' => 'oldpass',
        'new_password' => 'newpass123',
        'new_password_confirmation' => 'nope',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('new_password_confirmation');
});

it('denies update to a guest with a 403', function () {
    actingAsForProfile(null);

    $response = $this->postJson(profileEndpoint('update'), [
        'preferences' => ['language' => 'en'],
    ]);

    $response->assertStatus(403);
});
