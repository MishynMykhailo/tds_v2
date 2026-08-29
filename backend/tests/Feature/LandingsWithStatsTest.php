<?php

use App\Models\Click;
use App\Models\Landing;
use Database\Factories\LandingFactory;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| landings.withStats / landings.gridDefinition
|--------------------------------------------------------------------------
|
| Exercises App\Services\Grid\EntityGridBuilder (the generic port of legacy
| Component\EntityGrid\EntityGridFactory, reused as-is — see its docblock)
| through the real ?object=landings.withStats /
| ?object=landings.gridDefinition endpoints, grouped by `clicks.landing_id`
| instead of `campaign_id`.
|
| Same metric formulas as tests/Feature/CampaignsWithStatsTest.php /
| tests/Feature/StreamsWithStatsTest.php / tests/Feature/OffersWithStatsTest.php
| (verified against the real legacy `ReportDefinition::initColumns()` SQL —
| see App\Services\Grid\EntityGridBuilder docblocks):
|   clicks      = COUNT(click_id)
|   conversions = SUM(is_sale) + SUM(is_lead) + SUM(is_rejected)
|   revenue     = SUM(lead_revenue) + SUM(sale_revenue)
|   cost        = SUM(cost)
|   profit      = revenue - cost
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
| Reuses landingsEndpoint()/actingAsAdminForLandings() already declared
| globally by tests/Feature/LandingsTest.php (Pest loads every test file
| into one process before running any test, so these are already available
| here — redeclaring them would be a fatal "cannot redeclare function").
|
*/

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForLandings($admin);
});

/** Minimal valid `clicks` row for the given landing, with per-row overrides. */
function makeLandingClick(Landing $landing, array $overrides = []): Click
{
    static $counter = 0;
    $counter++;

    return Click::create(array_merge([
        'visitor_id' => 4000 + $counter,
        'sub_id' => 'landing-stats-sub-'.$landing->id.'-'.$counter,
        'datetime' => now()->subMinutes($counter),
        'campaign_id' => 1,
        'landing_id' => $landing->id,
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

it('computes clicks/conversions/revenue/cost/profit correctly for a landing with clicks', function () {
    $landing = LandingFactory::new()->create(['name' => 'Stats Landing']);

    // 2 sales, 1 lead, 1 rejected, 1 plain click — cost=1 on every click.
    makeLandingClick($landing, ['is_sale' => true, 'sale_revenue' => 20, 'cost' => 1]);
    makeLandingClick($landing, ['is_sale' => true, 'sale_revenue' => 15, 'cost' => 1]);
    makeLandingClick($landing, ['is_lead' => true, 'lead_revenue' => 5, 'cost' => 1]);
    makeLandingClick($landing, ['is_rejected' => true, 'rejected_revenue' => 8, 'cost' => 1]);
    makeLandingClick($landing, ['cost' => 1]);

    $response = $this->postJson(landingsEndpoint('withStats'), [
        'metrics' => ['clicks', 'conversions', 'revenue', 'cost', 'profit'],
    ]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['rows', 'meta']);
    expect($data['meta']['total'])->toBe(1);

    $row = collect($data['rows'])->firstWhere('id', $landing->id);
    expect($row)->not->toBeNull();

    expect($row['clicks'])->toBe(5);
    expect($row['conversions'])->toBe(4);
    expect((float) $row['revenue'])->toBe(40.0);
    expect((float) $row['cost'])->toBe(5.0);
    expect((float) $row['profit'])->toBe(35.0);
});

it('includes a landing with zero clicks, zero-filled', function () {
    $landing = LandingFactory::new()->create(['name' => 'No Clicks Landing']);

    $response = $this->postJson(landingsEndpoint('withStats'), [
        'metrics' => ['clicks', 'conversions', 'revenue', 'cost', 'profit'],
    ]);

    $response->assertStatus(200);
    $row = collect($response->json('rows'))->firstWhere('id', $landing->id);

    expect($row)->not->toBeNull();
    expect($row['clicks'])->toBe(0);
    expect($row['conversions'])->toBe(0);
    expect((float) $row['revenue'])->toBe(0.0);
    expect((float) $row['cost'])->toBe(0.0);
    expect((float) $row['profit'])->toBe(0.0);
});

it('defaults to the base metric set when no `metrics` are requested', function () {
    $landing = LandingFactory::new()->create();
    makeLandingClick($landing, ['is_sale' => true, 'sale_revenue' => 10, 'cost' => 2]);

    $response = $this->postJson(landingsEndpoint('withStats'), []);

    $response->assertStatus(200);
    $row = collect($response->json('rows'))->firstWhere('id', $landing->id);

    expect($row)->toHaveKeys(['clicks', 'conversions', 'revenue', 'cost', 'profit']);
    expect($row['clicks'])->toBe(1);
});

it('never returns a deleted landing, even when explicitly filtered by it', function () {
    $deleted = LandingFactory::new()->create(['name' => 'Deleted Landing', 'state' => 'deleted']);

    $response = $this->postJson(landingsEndpoint('withStats'), [
        'metrics' => ['clicks'],
    ]);

    $response->assertStatus(200);
    $ids = collect($response->json('rows'))->pluck('id');

    expect($ids)->not->toContain($deleted->id);
});

it('returns the landings gridDefinition with the expected minimal column set', function () {
    $response = $this->getJson(landingsEndpoint('gridDefinition'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
    expect($data['url'])->toBe('?object=landings.withStats');
    expect($data['range_intervals'])->toBeNull();

    $columnNames = collect($data['columns'])->pluck('name');
    foreach (['id', 'name', 'clicks', 'conversions', 'revenue', 'cost', 'profit'] as $expected) {
        expect($columnNames)->toContain($expected);
    }

    $clicksColumn = collect($data['columns'])->firstWhere('name', 'clicks');
    expect($clicksColumn['metric'])->toBeTrue();
});
