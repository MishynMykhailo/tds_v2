<?php

use App\Models\User;
use App\Services\AuthService;
use Database\Factories\ApiKeyFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| ApiKeys compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=apikeys.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\ApiKeysController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Contract reference:
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function apiKeysEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "apikeys.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForApiKeys(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

it('lists the current user\'s own keys', function () {
    $user = UserFactory::new()->create();
    ApiKeyFactory::new()->forUser($user)->count(2)->create();
    ApiKeyFactory::new()->count(3)->create(); // someone else's keys
    actingAsForApiKeys($user);

    $response = $this->getJson(apiKeysEndpoint('getAll'));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data)->toHaveCount(2);
    foreach ($data as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('key');
        expect($item)->toHaveKey('datetime');
    }
});

it('denies index to a guest with a 403', function () {
    actingAsForApiKeys(null);

    $response = $this->getJson(apiKeysEndpoint('getAll'));

    $response->assertStatus(403);
});

it('lets an admin list another user\'s keys via userId', function () {
    $admin = UserFactory::new()->admin()->create();
    $target = UserFactory::new()->create();
    ApiKeyFactory::new()->forUser($target)->count(2)->create();
    actingAsForApiKeys($admin);

    $response = $this->getJson(apiKeysEndpoint('getAll', ['userId' => $target->id]));

    $response->assertStatus(200);
    expect($response->json())->toHaveCount(2);
});

it('denies a non-admin from listing another user\'s keys via userId with a 403', function () {
    $user = UserFactory::new()->create();
    $target = UserFactory::new()->create();
    ApiKeyFactory::new()->forUser($target)->create();
    actingAsForApiKeys($user);

    $response = $this->getJson(apiKeysEndpoint('getAll', ['userId' => $target->id]));

    $response->assertStatus(403);
});

it('creates a random 32-char hex key for the current user', function () {
    $user = UserFactory::new()->create();
    actingAsForApiKeys($user);

    $response = $this->postJson(apiKeysEndpoint('add'));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data['key'])->toMatch('/^[a-f0-9]{32}$/');

    $this->assertDatabaseHas('api_keys', ['key' => $data['key'], 'user_id' => $user->id]);
});

it('denies create to a guest with a 403', function () {
    actingAsForApiKeys(null);

    $response = $this->postJson(apiKeysEndpoint('add'));

    $response->assertStatus(403);
});

it('removes one of the current user\'s own keys', function () {
    $user = UserFactory::new()->create();
    $key = ApiKeyFactory::new()->forUser($user)->create();
    actingAsForApiKeys($user);

    $response = $this->postJson(apiKeysEndpoint('delete', ['keyId' => $key->id]));

    $response->assertStatus(200);
    $this->assertDatabaseMissing('api_keys', ['id' => $key->id]);
});

it('returns 404 removing a key that belongs to someone else', function () {
    $user = UserFactory::new()->create();
    $otherKey = ApiKeyFactory::new()->create();
    actingAsForApiKeys($user);

    $response = $this->postJson(apiKeysEndpoint('delete', ['keyId' => $otherKey->id]));

    $response->assertStatus(404);
    $this->assertDatabaseHas('api_keys', ['id' => $otherKey->id]);
});

it('returns 404 removing a non-existent key', function () {
    $user = UserFactory::new()->create();
    actingAsForApiKeys($user);

    $response = $this->postJson(apiKeysEndpoint('delete', ['keyId' => 999999]));

    $response->assertStatus(404);
});
