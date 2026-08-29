<?php

use App\Models\AclRule;
use App\Models\Campaign;
use App\Services\AclService;
use Database\Factories\CampaignFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| AclService tests
|--------------------------------------------------------------------------
|
| Exercises App\Services\AclService directly (not through HTTP), against
| the legacy `Component\Users\Service\AclService` contract described in
| docs/legacy-reference/frontend/backend_api_reference.md §5 and confirmed
| by reading the real old source
| (application/Component/Users/Service/AclService.php).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
*/

function acl(): AclService
{
    return app(AclService::class);
}

// ---------------------------------------------------------------
// isResourceAllowed()
// ---------------------------------------------------------------

it('denies isResourceAllowed for a null (guest) user', function () {
    expect(acl()->isResourceAllowed(null, 'campaigns'))->toBeFalse();
});

it('allows isResourceAllowed for an admin regardless of acl_resources', function () {
    $admin = UserFactory::new()->admin()->create();

    expect(acl()->isResourceAllowed($admin, 'campaigns'))->toBeTrue();
});

it('allows isResourceAllowed only for resources listed in acl_resources', function () {
    $user = UserFactory::new()->create();
    $user->aclResource()->create(['resources' => ['campaigns', 'streams']]);

    expect(acl()->isResourceAllowed($user, 'campaigns'))->toBeTrue();
    expect(acl()->isResourceAllowed($user, 'offers'))->toBeFalse();
});

it('denies isResourceAllowed for a user with no acl_resources row at all', function () {
    $user = UserFactory::new()->create();

    expect(acl()->isResourceAllowed($user, 'campaigns'))->toBeFalse();
});

// ---------------------------------------------------------------
// filterByAcl()
// ---------------------------------------------------------------

it('filterByAcl returns nothing for a null (guest) user', function () {
    $campaigns = CampaignFactory::new()->count(3)->create();

    expect(acl()->filterByAcl($campaigns, false, null))->toBe([]);
});

it('filterByAcl returns the full list for an admin', function () {
    $admin = UserFactory::new()->admin()->create();
    $campaigns = CampaignFactory::new()->count(3)->create();

    expect(acl()->filterByAcl($campaigns, false, $admin))->toHaveCount(3);
});

it('filterByAcl returns nothing for a non-admin with no matching acl_rules row', function () {
    $user = UserFactory::new()->create();
    $campaigns = CampaignFactory::new()->count(3)->create();

    expect(acl()->filterByAcl($campaigns, false, $user))->toBe([]);
});

it('filterByAcl full_access returns everything for both view and edit', function () {
    $user = UserFactory::new()->create();
    AclRule::create(['user_id' => $user->id, 'entity_type' => 'campaigns', 'access_type' => AclRule::FULL_ACCESS]);
    $campaigns = CampaignFactory::new()->count(2)->create();

    expect(acl()->filterByAcl($campaigns, false, $user))->toHaveCount(2);
    expect(acl()->filterByAcl($campaigns, true, $user))->toHaveCount(2);
});

it('filterByAcl read_only returns everything for view but nothing for edit', function () {
    $user = UserFactory::new()->create();
    AclRule::create(['user_id' => $user->id, 'entity_type' => 'campaigns', 'access_type' => AclRule::READ_ONLY]);
    $campaigns = CampaignFactory::new()->count(2)->create();

    expect(acl()->filterByAcl($campaigns, false, $user))->toHaveCount(2);
    expect(acl()->filterByAcl($campaigns, true, $user))->toHaveCount(0);
});

it('filterByAcl to_groups_and_selected keeps only explicitly listed entity ids', function () {
    $user = UserFactory::new()->create();
    $allowed = CampaignFactory::new()->create();
    $denied = CampaignFactory::new()->create();

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowed->id],
    ]);

    $result = acl()->filterByAcl(collect([$allowed, $denied]), false, $user);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe($allowed->id);
});

it('filterByAcl to_groups_and_selected also matches by campaign group_id', function () {
    $user = UserFactory::new()->create();
    $allowed = CampaignFactory::new()->create(['group_id' => 7]);
    $denied = CampaignFactory::new()->create(['group_id' => 8]);

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'groups' => [7],
    ]);

    $result = acl()->filterByAcl(collect([$allowed, $denied]), false, $user);

    expect($result)->toHaveCount(1);
    expect($result[0]->id)->toBe($allowed->id);
});

// ---------------------------------------------------------------
// isViewAllowed() / isEditAllowed()
// ---------------------------------------------------------------

it('isViewAllowed/isEditAllowed deny a null (guest) user', function () {
    $campaign = CampaignFactory::new()->create();

    expect(acl()->isViewAllowed(null, $campaign))->toBeFalse();
    expect(acl()->isEditAllowed(null, $campaign))->toBeFalse();
});

it('isViewAllowed/isEditAllowed allow an admin unconditionally', function () {
    $admin = UserFactory::new()->admin()->create();
    $campaign = CampaignFactory::new()->create();

    expect(acl()->isViewAllowed($admin, $campaign))->toBeTrue();
    expect(acl()->isEditAllowed($admin, $campaign))->toBeTrue();
});

it('isEditAllowed denies a read_only rule but isViewAllowed allows it', function () {
    $user = UserFactory::new()->create();
    $campaign = CampaignFactory::new()->create();
    AclRule::create(['user_id' => $user->id, 'entity_type' => 'campaigns', 'access_type' => AclRule::READ_ONLY]);

    expect(acl()->isViewAllowed($user, $campaign))->toBeTrue();
    expect(acl()->isEditAllowed($user, $campaign))->toBeFalse();
});

// ---------------------------------------------------------------
// isCreateAllowed()
// ---------------------------------------------------------------

it('isCreateAllowed denies a null (guest) user', function () {
    expect(acl()->isCreateAllowed(null, 'campaigns'))->toBeFalse();
});

it('isCreateAllowed allows an admin unconditionally', function () {
    $admin = UserFactory::new()->admin()->create();

    expect(acl()->isCreateAllowed($admin, 'campaigns'))->toBeTrue();
});

it('isCreateAllowed follows AclRule::createAllowed() per access_type', function () {
    $user = UserFactory::new()->create();
    AclRule::create(['user_id' => $user->id, 'entity_type' => 'campaigns', 'access_type' => AclRule::TO_GROUPS_AND_SELECTED]);

    expect(acl()->isCreateAllowed($user, 'campaigns'))->toBeFalse();

    $rule = AclRule::where('user_id', $user->id)->first();
    $rule->update(['access_type' => AclRule::CREATED_BY_USER_GROUPS_AND_SELECTED]);

    expect(acl()->isCreateAllowed($user, 'campaigns'))->toBeTrue();
});

// ---------------------------------------------------------------
// addAuthorPermission()
// ---------------------------------------------------------------

it('addAuthorPermission grants the creator access under created_by_user_groups_and_selected', function () {
    $user = UserFactory::new()->create();
    $campaign = CampaignFactory::new()->create();
    AclRule::create(['user_id' => $user->id, 'entity_type' => 'campaigns', 'access_type' => AclRule::CREATED_BY_USER_GROUPS_AND_SELECTED]);

    acl()->addAuthorPermission($user, $campaign);

    $rule = AclRule::where('user_id', $user->id)->where('entity_type', 'campaigns')->first();
    expect($rule->entities)->toContain($campaign->id);

    // Now the user can view/edit their own creation via to_groups-style matching.
    expect(acl()->isEditAllowed($user, $campaign))->toBeTrue();
});

it('addAuthorPermission is a no-op for full_access (no per-entity tracking needed)', function () {
    $user = UserFactory::new()->create();
    $campaign = CampaignFactory::new()->create();
    AclRule::create(['user_id' => $user->id, 'entity_type' => 'campaigns', 'access_type' => AclRule::FULL_ACCESS]);

    acl()->addAuthorPermission($user, $campaign);

    $rule = AclRule::where('user_id', $user->id)->where('entity_type', 'campaigns')->first();
    expect($rule->entities ?? [])->toBe([]);
});

it('addAuthorPermission is a no-op for an admin', function () {
    $admin = UserFactory::new()->admin()->create();
    $campaign = CampaignFactory::new()->create();

    // Must not throw even though the admin has no acl_rules row at all.
    acl()->addAuthorPermission($admin, $campaign);

    expect(AclRule::where('user_id', $admin->id)->count())->toBe(0);
});
