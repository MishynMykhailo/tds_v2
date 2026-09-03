<?php

use App\Models\Campaign;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\GroupFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Campaigns compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=campaigns.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\CampaignsController) through Laravel's internal
| HTTP testing helpers (getJson/postJson) — no external HTTP calls.
|
| Contract reference: docs/legacy-reference/frontend/backend_api_reference.md
| §10.1 (Campaigns).
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
|
| Campaign::factory() is NOT used on purpose: App\Models\Campaign does not
| (yet) use the HasFactory trait — that file belongs to another module and
| this test suite is scoped to tests/ + database/factories/ only. The
| Factory base class works standalone via CampaignFactory::new() because
| CampaignFactory declares `protected $model = Campaign::class;` itself.
|
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function campaignsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "campaigns.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Makes App\Http\Middleware\LegacyAuthMiddleware resolve $user as the
 * current user on the next request, regardless of the (absent) `states`
 * cookie. Same indirection as tests/Feature/AclTest.php::actingAsForAcl()
 * (duplicated under a distinct name — Pest loads every test file into one
 * process, so two files can't both declare a global `actingAsForAcl()`):
 * the middleware unconditionally re-derives CurrentUserService from the
 * cookie on every request, so a plain CurrentUserService::set() call would
 * get silently clobbered back to null before the controller runs. Mocking
 * AuthService::verifyFromCookie() sidesteps that (and sidesteps
 * AuthService's real cookie/JWT verification path, which is exercised
 * elsewhere, not here).
 */
function actingAsAdminForCampaigns(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

/*
 * AclService is now wired into every ACL-gated action in
 * CampaignsController (see App\Services\AclService and its "// TODO: ACL"
 * removals in the controller). Admins bypass ACL entirely
 * (User::isAdmin() === true short-circuits every AclService check per §5),
 * so authenticating as an admin here keeps every existing assertion in this
 * file valid without needing per-test ACL rule fixtures.
 */
beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForCampaigns($admin);
});

it('lists campaigns as a JSON array', function () {
    CampaignFactory::new()->count(3)->create();

    $response = $this->getJson(campaignsEndpoint('index'));

    $response->assertStatus(200);

    expect($response->json())->toBeArray()->and($response->json())->toHaveCount(3);
});

it('shows a campaign with every model field and never exposes cost_type=CPV', function () {
    $campaign = CampaignFactory::new()->withCpvCostType()->create();

    $response = $this->getJson(campaignsEndpoint('show', ['id' => $campaign->id]));

    $response->assertStatus(200);

    $data = $response->json();

    // `mode` is intentionally stripped by CampaignSerializer::extra()
    // (see backend_api_reference.md §10.1: "удаляется служебное поле
    // mode") — every other fillable column must round-trip.
    $expectedFields = array_diff((new Campaign)->getFillable(), ['mode']);
    foreach ($expectedFields as $field) {
        expect($data)->toHaveKey($field);
    }
    expect($data)->toHaveKey('id');

    // Legacy display substitution: CPV is never returned as-is.
    expect($data['cost_type'])->not->toBe('CPV');
    expect($data['cost_type'])->toBe('CPC');
});

it('returns 404 for show without a valid id', function () {
    $response = $this->getJson(campaignsEndpoint('show'));

    $response->assertStatus(404);
});

it('returns 404 for show with a non-existent id', function () {
    $response = $this->getJson(campaignsEndpoint('show', ['id' => 999999]));

    $response->assertStatus(404);
});

it('creates a campaign given a valid name and alias', function () {
    $payload = [
        'name' => 'Summer Push',
        'alias' => 'summer-push-1',
    ];

    $response = $this->postJson(campaignsEndpoint('create'), $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('campaigns', [
        'name' => 'Summer Push',
        'alias' => 'summer-push-1',
    ]);
});

it('rejects campaign creation without a name with a 406 and a name error', function () {
    $response = $this->postJson(campaignsEndpoint('create'), [
        'alias' => 'missing-name-campaign',
    ]);

    $response->assertStatus(406);

    $data = $response->json();
    expect($data)->toHaveKey('name');

    $this->assertDatabaseMissing('campaigns', [
        'alias' => 'missing-name-campaign',
    ]);
});

it('creates a campaign together with its nested streams', function () {
    // §10.1/§10.2: campaigns.create can accept a nested `streams` array so
    // the campaign and its streams are created together in one call (mirrors
    // the old StreamService::updateStreams() nested-create behavior).
    //
    $payload = [
        'name' => 'Campaign With Streams',
        'alias' => 'campaign-with-streams-1',
        'streams' => [
            [
                'name' => 'Nested Stream One',
                'schema' => 'LANDINGS',
                // action_type is required (StreamsController::validateStreamParams())
                // — without it createStreamRecord() returns a silent
                // ['errors'=>...] that saveNestedStreams() currently drops
                // (tracked TODO in CampaignsController::saveNestedStreams).
                'action_type' => 'http',
            ],
        ],
    ];

    $response = $this->postJson(campaignsEndpoint('create'), $payload);

    $response->assertStatus(200);

    $this->assertDatabaseHas('campaigns', [
        'name' => 'Campaign With Streams',
        'alias' => 'campaign-with-streams-1',
    ]);

    $campaign = Campaign::where('alias', 'campaign-with-streams-1')->firstOrFail();

    $this->assertDatabaseHas('streams', [
        'campaign_id' => $campaign->id,
        'name' => 'Nested Stream One',
    ]);
});

it('lists campaigns as options in the {id,name,group_id,group,value} shape', function () {
    CampaignFactory::new()->count(2)->create();

    $response = $this->getJson(campaignsEndpoint('listAsOptions'));

    $response->assertStatus(200);

    $data = $response->json();

    expect($data)->toBeArray()->and($data)->toHaveCount(2);

    foreach ($data as $item) {
        expect($item)->toHaveKeys(['id', 'name', 'group_id', 'group', 'value']);
    }
});

it('listAsOptions resolves the real group name, and defaults ungrouped campaigns to "Default"/group_id 0', function () {
    $group = GroupFactory::new()->create(['name' => 'My Real Group']);
    $grouped = CampaignFactory::new()->create(['group_id' => $group->id]);
    $ungrouped = CampaignFactory::new()->create(['group_id' => 0]);

    $response = $this->getJson(campaignsEndpoint('listAsOptions'));

    $response->assertStatus(200);
    $byId = collect($response->json())->keyBy('id');

    expect($byId[$grouped->id]['group'])->toBe('My Real Group');
    expect($byId[$grouped->id]['group_id'])->toBe($group->id);
    expect($byId[$ungrouped->id]['group'])->toBe('Default');
    expect($byId[$ungrouped->id]['group_id'])->toBe(0);
});

it('show (extended) resolves the real group name for a grouped campaign', function () {
    $group = GroupFactory::new()->create(['name' => 'Extended Group']);
    $campaign = CampaignFactory::new()->create(['group_id' => $group->id]);

    $response = $this->getJson(campaignsEndpoint('show', ['id' => $campaign->id]));

    $response->assertStatus(200);
    expect($response->json('group'))->toBe('Extended Group');
});

it('denies a guest (no current user) access to view a campaign with a 403', function () {
    $campaign = CampaignFactory::new()->create();
    actingAsAdminForCampaigns(null);

    $response = $this->getJson(campaignsEndpoint('show', ['id' => $campaign->id]));

    $response->assertStatus(403);
    expect($response->json())->toHaveKey('error');
});

it('denies a guest (no current user) access to create a campaign with a 403', function () {
    actingAsAdminForCampaigns(null);

    $response = $this->postJson(campaignsEndpoint('create'), [
        'name' => 'Guest Campaign',
        'alias' => 'guest-campaign-1',
    ]);

    $response->assertStatus(403);

    $this->assertDatabaseMissing('campaigns', ['alias' => 'guest-campaign-1']);
});

it('denies a guest (no current user) access to update a campaign with a 403', function () {
    $campaign = CampaignFactory::new()->create(['name' => 'Original']);
    actingAsAdminForCampaigns(null);

    $response = $this->postJson(campaignsEndpoint('update', ['id' => $campaign->id]), [
        'name' => 'Changed',
    ]);

    $response->assertStatus(403);
    $this->assertDatabaseHas('campaigns', ['id' => $campaign->id, 'name' => 'Original']);
});
