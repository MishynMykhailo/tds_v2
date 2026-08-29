<?php

use App\Models\AclRule;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\GroupFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Groups compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=groups.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\GroupsController) through Laravel's internal
| HTTP testing helpers — no external HTTP calls.
|
| Contract reference:
| docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function groupsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "groups.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

function actingAsForGroups(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $this->admin = UserFactory::new()->admin()->create();
    actingAsForGroups($this->admin);
});

it('lists groups filtered by type', function () {
    GroupFactory::new()->count(2)->create(); // type=campaigns
    GroupFactory::new()->forOffers()->create();

    $response = $this->getJson(groupsEndpoint('index', ['type' => 'campaigns']));

    $response->assertStatus(200);

    $data = $response->json();
    expect($data)->toBeArray()->and($data)->toHaveCount(2);
    foreach ($data as $item) {
        expect($item['type'])->toBe('campaigns');
    }
});

it('includes a count when extended=1', function () {
    $group = GroupFactory::new()->create();
    CampaignFactory::new()->count(3)->create(['group_id' => $group->id]);

    $response = $this->getJson(groupsEndpoint('index', ['type' => 'campaigns', 'extended' => '1']));

    $response->assertStatus(200);
    $data = $response->json();
    $item = collect($data)->firstWhere('id', $group->id);
    expect($item['count'])->toBe(3);
});

it('shows a single group', function () {
    $group = GroupFactory::new()->create();

    $response = $this->getJson(groupsEndpoint('show', ['id' => $group->id]));

    $response->assertStatus(200);
    expect($response->json('name'))->toBe($group->name);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(groupsEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates a group given a valid name/type', function () {
    $response = $this->postJson(groupsEndpoint('create'), [
        'name' => 'My Campaign Group',
        'type' => 'campaigns',
    ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('groups', [
        'name' => 'My Campaign Group',
        'type' => 'campaigns',
    ]);
});

it('rejects group creation without a name with a 406', function () {
    $response = $this->postJson(groupsEndpoint('create'), [
        'type' => 'campaigns',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('name');
});

it('rejects group creation with an invalid type with a 406', function () {
    $response = $this->postJson(groupsEndpoint('create'), [
        'name' => 'Bad Type Group',
        'type' => 'bogus',
    ]);

    $response->assertStatus(406);
    expect($response->json())->toHaveKey('type');

    $this->assertDatabaseMissing('groups', ['name' => 'Bad Type Group']);
});

it('updates a group\'s name', function () {
    $group = GroupFactory::new()->create(['name' => 'Old Name']);

    $response = $this->postJson(groupsEndpoint('update', ['id' => $group->id]), [
        'name' => 'New Name',
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseHas('groups', ['id' => $group->id, 'name' => 'New Name']);
});

it('returns 404 updating a non-existent group', function () {
    $response = $this->postJson(groupsEndpoint('update', ['id' => 999999]), [
        'name' => 'Irrelevant',
    ]);

    $response->assertStatus(404);
});

it('lists groups as options', function () {
    GroupFactory::new()->count(2)->create();

    $response = $this->getJson(groupsEndpoint('listAsOptions', ['type' => 'campaigns']));

    $response->assertStatus(200);
    $data = $response->json();
    expect($data)->toHaveCount(2);
    foreach ($data as $item) {
        expect($item)->toHaveKey('id');
        expect($item)->toHaveKey('name');
    }
});

it('denies a guest (no current user) access to create a group with a 403', function () {
    actingAsForGroups(null);

    $response = $this->postJson(groupsEndpoint('create'), [
        'name' => 'Guest Group',
        'type' => 'campaigns',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseMissing('groups', ['name' => 'Guest Group']);
});

it('denies a non-admin user without an ACL create-allowed rule from creating a group', function () {
    $user = UserFactory::new()->create();
    actingAsForGroups($user);

    $response = $this->postJson(groupsEndpoint('create'), [
        'name' => 'No Access Group',
        'type' => 'campaigns',
    ]);

    $response->assertStatus(403);
});

it('allows a non-admin user with a full_access campaigns rule to create and edit campaign groups', function () {
    $user = UserFactory::new()->create();
    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::FULL_ACCESS,
    ]);
    actingAsForGroups($user);

    $response = $this->postJson(groupsEndpoint('create'), [
        'name' => 'Allowed Group',
        'type' => 'campaigns',
    ]);

    $response->assertStatus(200);
    $groupId = $response->json('id');

    $updateResponse = $this->postJson(groupsEndpoint('update', ['id' => $groupId]), [
        'name' => 'Allowed Group Renamed',
    ]);
    $updateResponse->assertStatus(200);
});

it('denies editing a group to a read-only-rule user, but allows viewing it', function () {
    $group = GroupFactory::new()->create();

    $user = UserFactory::new()->create();
    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::READ_ONLY,
    ]);
    actingAsForGroups($user);

    $this->getJson(groupsEndpoint('show', ['id' => $group->id]))->assertStatus(200);

    $response = $this->postJson(groupsEndpoint('update', ['id' => $group->id]), [
        'name' => 'Should Not Change',
    ]);
    $response->assertStatus(403);
});
