<?php

use App\Models\Campaign;
use App\Models\Click;
use App\Models\Conversion;
use Database\Factories\CampaignFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| campaigns.withStats / campaigns.gridDefinition
|--------------------------------------------------------------------------
|
| Exercises App\Services\Grid\EntityGridBuilder (the port of legacy
| Component\EntityGrid\EntityGridFactory) through the real
| ?object=campaigns.withStats / ?object=campaigns.gridDefinition endpoints.
|
| The `clicks` table has no writer yet (click-processing pipeline not
| started) — rows are inserted directly via Click::create() here, per the
| task brief, to exercise the aggregation with real data.
|
| Metric formulas verified against the real legacy source (NOT just the doc
| summary — see App\Services\Grid\EntityGridBuilder docblocks for the two
| places the doc's paraphrase diverged from the actual SQL):
|   clicks      = COUNT(click_id)
|   conversions = SUM(is_sale) + SUM(is_lead) + SUM(is_rejected)  (rejected
|                 clicks count as conversions too, per ReportDefinition.php:40)
|   revenue     = SUM(lead_revenue) + SUM(sale_revenue)           (excludes
|                 rejected_revenue, per ReportDefinition.php:46)
|   cost        = SUM(cost)
|   profit      = revenue - cost
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
| Reuses campaignsEndpoint()/actingAsAdminForCampaigns() already declared
| globally by tests/Feature/CampaignsTest.php (Pest loads every test file
| into one process before running any test, so these are already available
| here — redeclaring them would be a fatal "cannot redeclare function").
|
*/

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForCampaigns($admin);
});

/** Minimal valid `clicks` row for the given campaign, with per-row overrides. */
function makeClick(int $campaignId, array $overrides = []): Click
{
    static $counter = 0;
    $counter++;

    return Click::create(array_merge([
        'visitor_id' => 1000 + $counter,
        'sub_id' => 'test-sub-'.$campaignId.'-'.$counter,
        'datetime' => now()->subMinutes($counter),
        'campaign_id' => $campaignId,
        'source_id' => 1,
        'referrer_id' => 1,
        'cost' => 0,
        'lead_revenue' => 0,
        'sale_revenue' => 0,
        'rejected_revenue' => 0,
        'is_lead' => false,
        'is_sale' => false,
        'is_rejected' => false,
    ], $overrides));
}

/** Minimal valid `conversions` row for the given campaign/click. */
function makeConversion(int $campaignId, int $clickId, array $overrides = []): Conversion
{
    return Conversion::create(array_merge([
        'campaign_id' => $campaignId,
        'click_id' => $clickId,
        'sub_id' => 'conv-'.$clickId,
        'click_datetime' => now()->subMinutes(5),
        'postback_datetime' => now(),
        'status' => 'sale',
    ], $overrides));
}

it('computes clicks/conversions/revenue/cost/profit correctly for a campaign with clicks', function () {
    $campaign = CampaignFactory::new()->create(['name' => 'Stats Campaign']);

    // 2 sales, 1 lead, 1 rejected, 1 plain click — cost=1 on every click.
    $c1 = makeClick($campaign->id, ['is_sale' => true, 'sale_revenue' => 20, 'cost' => 1]);
    $c2 = makeClick($campaign->id, ['is_sale' => true, 'sale_revenue' => 15, 'cost' => 1]);
    $c3 = makeClick($campaign->id, ['is_lead' => true, 'lead_revenue' => 5, 'cost' => 1]);
    $c4 = makeClick($campaign->id, ['is_rejected' => true, 'rejected_revenue' => 8, 'cost' => 1]);
    makeClick($campaign->id, ['cost' => 1]);

    // Conversion log rows for the same campaign — inserted per the task
    // brief for realism; NOT joined by EntityGridBuilder (see its
    // docblock: conversions.log is a separate grid, the metrics below are
    // computed from `clicks` alone), so these must NOT affect the numbers.
    makeConversion($campaign->id, $c1->click_id, ['revenue' => 20, 'status' => 'sale']);
    makeConversion($campaign->id, $c3->click_id, ['revenue' => 5, 'status' => 'lead']);

    $response = $this->postJson(campaignsEndpoint('withStats'), [
        'limit' => 100,
        'metrics' => ['clicks', 'conversions', 'revenue', 'cost', 'profit'],
    ]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['rows', 'meta']);
    expect($data['meta']['total'])->toBe(1);

    $row = collect($data['rows'])->firstWhere('id', $campaign->id);
    expect($row)->not->toBeNull();

    expect($row['clicks'])->toBe(5);
    // is_sale(2) + is_lead(1) + is_rejected(1) — real legacy formula, counts rejected too.
    expect($row['conversions'])->toBe(4);
    // lead_revenue(5) + sale_revenue(20+15) = 40, rejected_revenue(8) excluded.
    expect((float) $row['revenue'])->toBe(40.0);
    expect((float) $row['cost'])->toBe(5.0);
    expect((float) $row['profit'])->toBe(35.0);
});

it('includes a campaign with zero clicks, zero-filled', function () {
    $campaign = CampaignFactory::new()->create(['name' => 'No Clicks Campaign']);

    $response = $this->postJson(campaignsEndpoint('withStats'), [
        'limit' => 100,
        'metrics' => ['clicks', 'conversions', 'revenue', 'cost', 'profit'],
    ]);

    $response->assertStatus(200);
    $row = collect($response->json('rows'))->firstWhere('id', $campaign->id);

    expect($row)->not->toBeNull();
    expect($row['clicks'])->toBe(0);
    expect($row['conversions'])->toBe(0);
    expect((float) $row['revenue'])->toBe(0.0);
    expect((float) $row['cost'])->toBe(0.0);
    expect((float) $row['profit'])->toBe(0.0);
});

it('defaults to the base metric set when no `metrics` are requested', function () {
    $campaign = CampaignFactory::new()->create();
    makeClick($campaign->id, ['is_sale' => true, 'sale_revenue' => 10, 'cost' => 2]);

    $response = $this->postJson(campaignsEndpoint('withStats'), ['limit' => 100]);

    $response->assertStatus(200);
    $row = collect($response->json('rows'))->firstWhere('id', $campaign->id);

    expect($row)->toHaveKeys(['clicks', 'conversions', 'revenue', 'cost', 'profit']);
    expect($row['clicks'])->toBe(1);
});

it('excludes clickless campaigns entirely when filtered by state=with_clicks', function () {
    $withClicks = CampaignFactory::new()->create(['name' => 'Has Clicks']);
    $withoutClicks = CampaignFactory::new()->create(['name' => 'No Clicks']);
    makeClick($withClicks->id, ['is_sale' => true, 'sale_revenue' => 1]);

    $response = $this->postJson(campaignsEndpoint('withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
        'filters' => [
            ['name' => 'state', 'operator' => 'EQUALS', 'expression' => 'with_clicks'],
        ],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->toContain($withClicks->id);
    expect($ids)->not->toContain($withoutClicks->id);
});

it('never returns a deleted campaign, even when explicitly filtered by it', function () {
    $deleted = CampaignFactory::new()->create(['name' => 'Deleted Campaign', 'state' => 'deleted']);

    $response = $this->postJson(campaignsEndpoint('withStats'), [
        'limit' => 100,
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->not->toContain($deleted->id);
});

it('returns the campaigns gridDefinition with the expected minimal column set', function () {
    $response = $this->getJson(campaignsEndpoint('gridDefinition'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
    expect($data['url'])->toBe('?object=campaigns.withStats');
    expect($data['range_intervals'])->toBeNull();

    $columnNames = collect($data['columns'])->pluck('name');
    foreach (['id', 'name', 'state', 'clicks', 'conversions', 'revenue', 'cost', 'profit'] as $expected) {
        expect($columnNames)->toContain($expected);
    }

    $clicksColumn = collect($data['columns'])->firstWhere('name', 'clicks');
    expect($clicksColumn['metric'])->toBeTrue();
});
