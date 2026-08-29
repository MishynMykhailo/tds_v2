<?php

use App\Models\Label;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\CampaignFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| Labels compatibility endpoint tests
|--------------------------------------------------------------------------
|
| Hits the legacy-shaped `?object=labels.<action>` route (see
| App\Http\Controllers\ObjectDispatchController and
| App\Http\Controllers\Admin\LabelsController). Access is checked through
| the label's parent campaign (`isViewAllowed($campaign)`), same pattern as
| StreamFilters/Triggers.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=controller.action`. */
function labelsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "labels.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/**
 * Same indirection as tests/Feature/StreamsTest.php::actingAsAdminForStreams()
 * — duplicated under a distinct name since Pest loads every test file into
 * one process.
 */
function actingAsAdminForLabels(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForLabels($admin);
});

it('returns the label_name catalogue', function () {
    $response = $this->getJson(labelsEndpoint('labelVariations'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toBeArray()->toHaveCount(2);
    expect(array_column($data, 'value'))->toBe(['whitelist', 'blacklist']);
    foreach ($data as $entry) {
        expect($entry)->toHaveKeys(['value', 'label', 'icon']);
    }
});

it('returns the ref_name catalogue', function () {
    $response = $this->getJson(labelsEndpoint('refNameVariations'));

    $response->assertStatus(200);
    $values = array_column($response->json(), 'value');

    foreach (['ip', 'source', 'x_requested_with', 'ad_campaign_id', 'creative_id', 'keyword', 'sub_id_1', 'sub_id_10'] as $expected) {
        expect($values)->toContain($expected);
    }
    expect($values)->not->toContain('sub_id_11');
});

it('returns null when there are no labels for the campaign/ref_name', function () {
    $campaign = CampaignFactory::new()->create();

    $response = $this->getJson(labelsEndpoint('index', [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
    ]));

    $response->assertStatus(200);
    expect($response->getContent())->toBe('null');
});

it('lists labels for a campaign/ref_name as a value => label_name map', function () {
    $campaign = CampaignFactory::new()->create();
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'facebook.com', 'label_name' => 'whitelist']);
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'spam.com', 'label_name' => 'blacklist']);
    // Different campaign — must not leak in.
    $other = CampaignFactory::new()->create();
    Label::create(['campaign_id' => $other->id, 'ref_name' => 'source', 'ref_value' => 'facebook.com', 'label_name' => 'blacklist']);

    $response = $this->getJson(labelsEndpoint('index', [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
    ]));

    $response->assertStatus(200);
    expect($response->json())->toBe([
        'facebook.com' => 'whitelist',
        'spam.com' => 'blacklist',
    ]);
});

it('filters the index by label_name', function () {
    $campaign = CampaignFactory::new()->create();
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'facebook.com', 'label_name' => 'whitelist']);
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'spam.com', 'label_name' => 'blacklist']);

    $response = $this->getJson(labelsEndpoint('index', [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
        'label_name' => 'blacklist',
    ]));

    $response->assertStatus(200);
    expect($response->json())->toBe(['spam.com' => 'blacklist']);
});

it('creates/updates labels via update', function () {
    $campaign = CampaignFactory::new()->create();

    $response = $this->postJson(labelsEndpoint('update'), [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
        'items' => ['facebook.com' => 'whitelist', 'spam.com' => 'blacklist'],
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['success' => true]);

    $this->assertDatabaseHas('labels', [
        'campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'facebook.com', 'label_name' => 'whitelist',
    ]);
    $this->assertDatabaseHas('labels', [
        'campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'spam.com', 'label_name' => 'blacklist',
    ]);
});

it('deletes a label via update when its value is empty', function () {
    $campaign = CampaignFactory::new()->create();
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'facebook.com', 'label_name' => 'whitelist']);

    $response = $this->postJson(labelsEndpoint('update'), [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
        'items' => ['facebook.com' => ''],
    ]);

    $response->assertStatus(200);
    $this->assertDatabaseMissing('labels', [
        'campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'facebook.com',
    ]);
});

it('replaces the whole label list for a label_name/ref_name via replaceList', function () {
    $campaign = CampaignFactory::new()->create();
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'old.com', 'label_name' => 'blacklist']);
    Label::create(['campaign_id' => $campaign->id, 'ref_name' => 'source', 'ref_value' => 'kept.com', 'label_name' => 'blacklist']);

    $response = $this->postJson(labelsEndpoint('replaceList'), [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
        'label_name' => 'blacklist',
        'ref_values' => ['kept.com', 'new.com'],
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['success' => true]);

    $this->assertDatabaseMissing('labels', ['ref_value' => 'old.com']);
    $this->assertDatabaseHas('labels', ['ref_value' => 'kept.com', 'label_name' => 'blacklist']);
    $this->assertDatabaseHas('labels', ['ref_value' => 'new.com', 'label_name' => 'blacklist']);
    expect(Label::where('campaign_id', $campaign->id)->where('ref_name', 'source')->where('label_name', 'blacklist')->count())->toBe(2);
});

it('rejects replaceList with an empty label_name', function () {
    $campaign = CampaignFactory::new()->create();

    $response = $this->postJson(labelsEndpoint('replaceList'), [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
        'label_name' => '',
        'ref_values' => ['a.com'],
    ]);

    $response->assertStatus(406);
});

it('rejects an unknown ref_name', function () {
    $campaign = CampaignFactory::new()->create();

    $response = $this->getJson(labelsEndpoint('index', [
        'campaign_id' => $campaign->id,
        'ref_name' => 'not_a_real_ref_name',
    ]));

    $response->assertStatus(406);
});

it('denies a guest (no current user) access with a 403', function () {
    $campaign = CampaignFactory::new()->create();
    actingAsAdminForLabels(null);

    $response = $this->getJson(labelsEndpoint('index', [
        'campaign_id' => $campaign->id,
        'ref_name' => 'source',
    ]));

    $response->assertStatus(403);
});

it('returns 403 for a non-existent campaign', function () {
    $response = $this->getJson(labelsEndpoint('index', [
        'campaign_id' => 999999,
        'ref_name' => 'source',
    ]));

    $response->assertStatus(403);
});
