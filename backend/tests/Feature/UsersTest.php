<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\ApiKeyFactory;
use Database\Factories\UserFactory;
use Database\Factories\UserPreferenceFactory;
use Illuminate\Support\Facades\Hash;

/*
|--------------------------------------------------------------------------
| Users compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=users.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\UsersController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Contract reference:
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function usersEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "users.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as OffersTest.php::actingAsAdminForOffers() — see that
 * file's docblock for why this can't be a plain CurrentUserService::set().
 */
function actingAsForUsers(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $this->admin = UserFactory::new()->admin()->create();
    actingAsForUsers($this->admin);
});

it('lists users as a JSON array, never exposing password/password_hash', function () {
    UserFactory::new()->count(2)->create();

    $response = $this->getJson(usersEndpoint('index'));

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray()->and($data)->toHaveCount(3); // + $this->admin

    foreach ($data as $item) {
        expect($item)->not->toHaveKey('password');
        expect($item)->not->toHaveKey('password_hash');
        expect($item)->toHaveKey('login');
        expect($item)->toHaveKey('access_data');
        expect($item)->toHaveKey('keyCount');
        expect($item)->toHaveKey('preferences');
    }
});

it('denies index to a non-admin user with a 403', function () {
    $user = UserFactory::new()->create();
    actingAsForUsers($user);

    $response = $this->getJson(usersEndpoint('index'));

    $response->assertStatus(403);
});

it('denies index to a guest with a 403', function () {
    actingAsForUsers(null);

    $response = $this->getJson(usersEndpoint('index'));

    $response->assertStatus(403);
});

it('shows a user including computed keyCount/preferences, never the password hash', function () {
    $user = UserFactory::new()->create();
    ApiKeyFactory::new()->forUser($user)->count(2)->create();
    UserPreferenceFactory::new()->forUser($user)->create(['pref_name' => 'language', 'pref_value' => 'en']);

    $response = $this->getJson(usersEndpoint('show', ['id' => $user->id]));

    $response->assertStatus(200);

    $data = $response->json();
    expect($data['login'])->toBe($user->login);
    expect($data['keyCount'])->toBe(2);
    expect($data['preferences']['language'])->toBe('en');
    expect($data['preferences']['timezone'])->toBe('UTC'); // default, none set
    expect($data)->not->toHaveKey('password_hash');
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(usersEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates a user given valid login/type/new_password, hashing the password', function () {
    $response = $this->postJson(usersEndpoint('create'), [
        'login' => 'newuser',
        'type' => 'USER',
        'new_password' => 'secret123',
        'new_password_confirmation' => 'secret123',
    ]);

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->not->toHaveKey('password_hash');

    $created = User::where('login', 'newuser')->first();
    expect($created)->not->toBeNull();
    expect($created->password_hash)->not->toBe('secret123');
    expect(Hash::check('secret123', $created->password_hash))->toBeTrue();
});

it('rejects user creation without a login with a 406 and a login error', function () {
    $response = $this->postJson(usersEndpoint('create'), [
        'type' => 'USER',
        'new_password' => 'secret123',
        'new_password_confirmation' => 'secret123',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('login');
});

it('rejects user creation with mismatched password confirmation with a 406', function () {
    $response = $this->postJson(usersEndpoint('create'), [
        'login' => 'mismatched',
        'type' => 'USER',
        'new_password' => 'secret123',
        'new_password_confirmation' => 'different',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('new_password_confirmation');

    $this->assertDatabaseMissing('users', ['login' => 'mismatched']);
});

it('rejects user creation with an invalid type with a 406', function () {
    $response = $this->postJson(usersEndpoint('create'), [
        'login' => 'badtype',
        'type' => 'SUPERUSER',
        'new_password' => 'secret123',
        'new_password_confirmation' => 'secret123',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('type');
});

it('denies create to a non-admin user with a 403', function () {
    $user = UserFactory::new()->create();
    actingAsForUsers($user);

    $response = $this->postJson(usersEndpoint('create'), [
        'login' => 'shouldnotexist',
        'type' => 'USER',
        'new_password' => 'secret123',
        'new_password_confirmation' => 'secret123',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseMissing('users', ['login' => 'shouldnotexist']);
});

it('updates a user\'s login without touching the password', function () {
    $user = UserFactory::new()->create(['login' => 'oldlogin']);
    $originalHash = $user->password_hash;

    $response = $this->postJson(usersEndpoint('update', ['id' => $user->id]), [
        'login' => 'newlogin',
    ]);

    $response->assertStatus(200);

    $user->refresh();
    expect($user->login)->toBe('newlogin');
    expect($user->password_hash)->toBe($originalHash);
});

it('updates a user\'s password when new_password/new_password_confirmation match', function () {
    $user = UserFactory::new()->create();

    $response = $this->postJson(usersEndpoint('update', ['id' => $user->id]), [
        'new_password' => 'brandnewpass',
        'new_password_confirmation' => 'brandnewpass',
    ]);

    $response->assertStatus(200);

    $user->refresh();
    expect(Hash::check('brandnewpass', $user->password_hash))->toBeTrue();
});

it('rejects a password update with mismatched confirmation with a 406', function () {
    $user = UserFactory::new()->create();
    $originalHash = $user->password_hash;

    $response = $this->postJson(usersEndpoint('update', ['id' => $user->id]), [
        'new_password' => 'brandnewpass',
        'new_password_confirmation' => 'nope',
    ]);

    $response->assertStatus(406);

    $user->refresh();
    expect($user->password_hash)->toBe($originalHash);
});

it('returns 404 updating a non-existent user', function () {
    $response = $this->postJson(usersEndpoint('update', ['id' => 999999]), [
        'login' => 'irrelevant',
    ]);

    $response->assertStatus(404);
});

it('has no listAsOptions action, matching legacy (404)', function () {
    // Legacy `Component\Users\Controller\UsersController` has no
    // `listAsOptionsAction` at all (verified by reading the legacy source
    // directly — only index/create/show/update/delete/setAccessData exist).
    // A prior version of this port added one anyway ("harmless extra
    // endpoint"), which tests-contract/tests/UsersTest.php caught as a
    // contract mismatch (legacy 404s this object.action). Removed to match
    // legacy exactly — see docs/PORTING_LOG.md.
    $response = $this->getJson(usersEndpoint('listAsOptions'));

    $response->assertStatus(404);
});
