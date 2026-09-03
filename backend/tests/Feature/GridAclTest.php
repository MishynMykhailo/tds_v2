<?php

use App\Models\AclResource;
use App\Models\AclRule;
use App\Models\Click;
use App\Models\Conversion;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\OfferFactory;
use Database\Factories\StreamFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| ACL enforcement in App\Services\Grid\EntityGridBuilder / GridBuilder
|--------------------------------------------------------------------------
|
| Both grid builders used to load stats for EVERY entity/click/conversion
| regardless of the requesting user's ACL rules (see the "TODO: ACL not
| wired here yet" docblocks that used to sit on every *.withStatsAction()/
| reports.build/conversions.log controller method). This file exercises
| the fix through the real HTTP endpoints:
|   - Campaigns/Streams are filtered via AclService::getAllowedCampaignIds()
|     (campaign_id-based — Streams have no ACL entity_type of their own,
|     access always flows through the parent campaign).
|   - Offers (representative of Offers/Landings/TrafficSources, which all
|     go through the same code path in EntityGridBuilder::applyAcl()) are
|     filtered via AclService::filterByAcl() on the entity's own ACL
|     entity_type.
|   - reports.build / conversions.log (App\Services\Grid\GridBuilder) are
|     filtered by a `campaign_id IN (...)` SQL restriction built from the
|     same getAllowedCampaignIds(), short-circuiting to an empty result
|     with no DB query at all for ALLOW_NONE.
|
| Auth strategy: same AuthService::verifyFromCookie() mock already used by
| tests/Feature/AclTest.php / CampaignsWithStatsTest.php (see AclTest.php's
| file docblock for why a real cookie round-trip isn't viable yet).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=<object>.<action>`. */
function gridAclEndpoint(string $object, string $action, array $query = []): string
{
    $query = array_merge(['object' => "{$object}.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/** Makes LegacyAuthMiddleware resolve $user as the current user on the next request. */
function actingAsForGridAcl(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

// ---------------------------------------------------------------
// campaigns.withStats
// ---------------------------------------------------------------

it('campaigns.withStats: an ADMIN sees every campaign with stats', function () {
    $admin = UserFactory::new()->admin()->create();
    $c1 = CampaignFactory::new()->create();
    $c2 = CampaignFactory::new()->create();

    actingAsForGridAcl($admin);

    $response = $this->postJson(gridAclEndpoint('campaigns', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->toContain($c1->id, $c2->id);
});

it('campaigns.withStats: a USER with a to_groups_and_selected rule sees only the allowed campaign', function () {
    $user = UserFactory::new()->create();
    $allowed = CampaignFactory::new()->create(['name' => 'Allowed Campaign']);
    $denied = CampaignFactory::new()->create(['name' => 'Denied Campaign']);

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowed->id],
    ]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('campaigns', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->toContain($allowed->id);
    expect($ids)->not->toContain($denied->id);
});

it('campaigns.withStats: a USER with no acl_rules row at all sees no campaigns', function () {
    $user = UserFactory::new()->create();
    CampaignFactory::new()->create();

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('campaigns', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    expect($response->json('rows'))->toBe([]);
    expect($response->json('meta.total'))->toBe(0);
});

it('campaigns.withStats: a USER with a full_access rule sees every campaign', function () {
    $user = UserFactory::new()->create();
    $c1 = CampaignFactory::new()->create();
    $c2 = CampaignFactory::new()->create();

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::FULL_ACCESS,
    ]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('campaigns', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->toContain($c1->id, $c2->id);
});

// ---------------------------------------------------------------
// streams.withStats (ACL flows through the parent campaign, not a direct
// "streams" ACL entity_type — see App\Services\AclService class docblock)
// ---------------------------------------------------------------

it('streams.withStats: a USER only sees streams whose parent campaign is allowed', function () {
    $user = UserFactory::new()->create();
    $allowedCampaign = CampaignFactory::new()->create();
    $deniedCampaign = CampaignFactory::new()->create();

    $allowedStream = StreamFactory::new()->forCampaign($allowedCampaign)->create();
    $deniedStream = StreamFactory::new()->forCampaign($deniedCampaign)->create();

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowedCampaign->id],
    ]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('streams', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->toContain($allowedStream->id);
    expect($ids)->not->toContain($deniedStream->id);
});

// ---------------------------------------------------------------
// offers.withStats (own ACL entity_type "offers" — filterByAcl() path,
// representative of Landings/TrafficSources, which share the same
// EntityGridBuilder::applyAcl() branch)
// ---------------------------------------------------------------

it('offers.withStats: a USER with a to_groups_and_selected rule sees only the allowed offer', function () {
    $user = UserFactory::new()->create();
    $allowed = OfferFactory::new()->create(['name' => 'Allowed Offer']);
    $denied = OfferFactory::new()->create(['name' => 'Denied Offer']);

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'offers',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowed->id],
    ]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('offers', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->toContain($allowed->id);
    expect($ids)->not->toContain($denied->id);
});

it('offers.withStats: an unauthenticated (null) user sees no offers', function () {
    OfferFactory::new()->create();

    actingAsForGridAcl(null);

    $response = $this->postJson(gridAclEndpoint('offers', 'withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    expect($response->json('rows'))->toBe([]);
});

// ---------------------------------------------------------------
// reports.build (App\Services\Grid\GridBuilder against `clicks`)
// ---------------------------------------------------------------

it('reports.build: a USER only sees clicks whose campaign_id is allowed', function () {
    $user = UserFactory::new()->create();
    $allowedCampaign = CampaignFactory::new()->create();
    $deniedCampaign = CampaignFactory::new()->create();

    Click::create([
        'visitor_id' => 5001, 'sub_id' => 'grid-acl-1', 'datetime' => now(),
        'campaign_id' => $allowedCampaign->id, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);
    Click::create([
        'visitor_id' => 5002, 'sub_id' => 'grid-acl-2', 'datetime' => now(),
        'campaign_id' => $deniedCampaign->id, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowedCampaign->id],
    ]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('reports', 'build'), [
        'columns' => ['campaign_id', 'clicks'],
        'grouping' => ['campaign_id'],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $campaignIds = collect($response->json('rows'))->pluck('campaign_id');

    expect($campaignIds->all())->toBe([$allowedCampaign->id]);
});

it('reports.build: ALLOW_NONE (no acl_rules row) returns an empty result without hitting the DB filter path', function () {
    $user = UserFactory::new()->create();
    $campaign = CampaignFactory::new()->create();

    Click::create([
        'visitor_id' => 5003, 'sub_id' => 'grid-acl-3', 'datetime' => now(),
        'campaign_id' => $campaign->id, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('reports', 'build'), [
        'columns' => ['campaign_id', 'clicks'],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    expect($response->json('rows'))->toBe([]);
    expect($response->json('total'))->toBe(0);
});

// ---------------------------------------------------------------
// conversions.log (App\Services\Grid\GridBuilder against `conversions`)
// ---------------------------------------------------------------

it('conversions.log: a USER only sees conversions whose campaign_id is allowed', function () {
    $user = UserFactory::new()->create();
    $allowedCampaign = CampaignFactory::new()->create();
    $deniedCampaign = CampaignFactory::new()->create();

    Conversion::create([
        'campaign_id' => $allowedCampaign->id, 'click_id' => 9001, 'sub_id' => 'conv-acl-1',
        'click_datetime' => now()->subMinutes(10), 'postback_datetime' => now(), 'status' => 'sale',
        'revenue' => 10, 'cost' => 1,
    ]);
    Conversion::create([
        'campaign_id' => $deniedCampaign->id, 'click_id' => 9002, 'sub_id' => 'conv-acl-2',
        'click_datetime' => now()->subMinutes(10), 'postback_datetime' => now(), 'status' => 'sale',
        'revenue' => 20, 'cost' => 2,
    ]);

    AclRule::create([
        'user_id' => $user->id,
        'entity_type' => 'campaigns',
        'access_type' => AclRule::TO_GROUPS_AND_SELECTED,
        'entities' => [$allowedCampaign->id],
    ]);

    // "conversions" resource-level access - separate from the per-campaign
    // AclRule above. Real legacy gates the WHOLE controller on this first
    // (ConversionsController's class docblock, found live 2026-09-03);
    // without it, every action 403s before campaign filtering ever runs.
    AclResource::create(['user_id' => $user->id, 'resources' => ['conversions']]);

    actingAsForGridAcl($user);

    $response = $this->postJson(gridAclEndpoint('conversions', 'log'), [
        'columns' => ['campaign_id', 'conversion_id'],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $campaignIds = collect($response->json('rows'))->pluck('campaign_id');

    expect($campaignIds->all())->toBe([$allowedCampaign->id]);
});
