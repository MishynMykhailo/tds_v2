<?php

use App\Models\Click;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| reports.build / reports.definition
|--------------------------------------------------------------------------
|
| Exercises App\Http\Controllers\Admin\ReportsController through the real
| ?object=reports.<action> endpoints (App\Http\Controllers\
| ObjectDispatchController). `reports.build` goes through
| App\Services\Grid\GridBuilder (used as-is, not modified) against the
| `clicks` table.
|
| The `clicks` table has no writer yet (click-processing pipeline not
| started) — rows are inserted directly via the `makeReportClick()` helper
| below, per the task brief. A LOCAL helper is declared here (not a shared
| global like tests/Feature/CampaignsWithStatsTest.php's makeClick())
| specifically so this file has no load-order dependency on any other test
| file — it also passes when run standalone (e.g. `pest
| tests/Feature/ReportsTest.php` alone), unlike relying on a same-shaped
| helper declared elsewhere. Same defaults/shape as that file's makeClick().
|
| Metric formulas match App\Services\Grid\EntityGridBuilder::
| METRIC_EXPRESSIONS (ReportDefinition::initColumns(), verified against the
| real legacy source — see that class's docblocks):
|   clicks      = COUNT(click_id)
|   conversions = SUM(is_sale) + SUM(is_lead) + SUM(is_rejected)
|   revenue     = SUM(lead_revenue) + SUM(sale_revenue)
|   cost        = SUM(cost)
|   profit      = revenue - cost
|
| App\Services\Grid\GridBuilder now enforces ACL (campaign_id IN (...) via
| AclService::getAllowedCampaignIds(), see its docblock) — a request with no
| resolved user is treated as ALLOW_NONE (empty result), so every test here
| authenticates as an ADMIN (which resolves to ALLOW_ANY, i.e. unfiltered —
| identical to this file's pre-ACL behavior) via the same
| mock-AuthService::verifyFromCookie() indirection already used by
| tests/Feature/CampaignsWithStatsTest.php / AclTest.php. ACL filtering
| itself (non-admin/ALLOW_NONE/allowed-subset) is covered by
| tests/Feature/GridAclTest.php, not duplicated here.
|
| RefreshDatabase is applied to the whole Feature suite in tests/Pest.php.
*/

/** Build the legacy dispatcher URL for a given `object=reports.<action>`. */
function reportsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "reports.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/** Makes LegacyAuthMiddleware resolve $user as the current user on the next request. */
function actingAsAdminForReports(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForReports($admin);
});

/** Minimal valid `clicks` row for the given campaign, with per-row overrides. */
function makeReportClick(int $campaignId, array $overrides = []): Click
{
    static $counter = 0;
    $counter++;

    return Click::create(array_merge([
        'visitor_id' => 2000 + $counter,
        'sub_id' => 'report-sub-'.$campaignId.'-'.$counter,
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

it('returns the reports definition with the expected minimal column set', function () {
    $response = $this->getJson(reportsEndpoint('definition'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
    expect($data['url'])->toBe('?object=reports.build');
    expect($data['details'])->toBeNull();
    expect($data['range_intervals'])->toBeNull();

    $columnNames = collect($data['columns'])->pluck('name');
    foreach (['campaign_id', 'offer_id', 'landing_id', 'ts_id', 'stream_id', 'clicks', 'conversions', 'leads', 'sales', 'rejected', 'revenue', 'cost', 'profit'] as $expected) {
        expect($columnNames)->toContain($expected);
    }

    $clicksColumn = collect($data['columns'])->firstWhere('name', 'clicks');
    expect($clicksColumn['metric'])->toBeTrue();
});

it('aggregates reports.build metrics grouped by campaign_id', function () {
    makeReportClick(1, ['is_sale' => true, 'sale_revenue' => 20, 'cost' => 2]);
    makeReportClick(1, ['is_lead' => true, 'lead_revenue' => 5, 'cost' => 1]);
    makeReportClick(1, ['is_rejected' => true, 'rejected_revenue' => 8, 'cost' => 1]);
    makeReportClick(2, ['cost' => 1]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['campaign_id', 'clicks', 'conversions', 'leads', 'sales', 'rejected', 'revenue', 'cost', 'profit'],
        'grouping' => ['campaign_id'],
        'sort' => [['name' => 'campaign_id', 'order' => 'ASC']],
        'limit' => 50,
        'summary' => true,
    ]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['rows', 'total', 'summary', 'meta']);
    expect($data['total'])->toBe(2);

    $row1 = collect($data['rows'])->firstWhere('campaign_id', 1);
    expect($row1['clicks'])->toBe(3);
    expect($row1['conversions'])->toBe(3); // is_sale(1) + is_lead(1) + is_rejected(1)
    expect($row1['leads'])->toBe(1);
    expect($row1['sales'])->toBe(1);
    expect($row1['rejected'])->toBe(1);
    // lead_revenue(5) + sale_revenue(20) = 25, rejected_revenue(8) excluded.
    expect((float) $row1['revenue'])->toBe(25.0);
    expect((float) $row1['cost'])->toBe(4.0);
    expect((float) $row1['profit'])->toBe(21.0);

    $row2 = collect($data['rows'])->firstWhere('campaign_id', 2);
    expect($row2['clicks'])->toBe(1);
    expect($row2['conversions'])->toBe(0);
    expect((float) $row2['cost'])->toBe(1.0);

    expect($data['summary'])->not->toBeNull();
    expect((int) $data['summary']['clicks'])->toBe(4);
});

it('filters reports.build by campaign_id', function () {
    makeReportClick(10, ['cost' => 1]);
    makeReportClick(11, ['cost' => 1]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['campaign_id', 'clicks'],
        'grouping' => ['campaign_id'],
        'filters' => [
            ['name' => 'campaign_id', 'operator' => 'EQUALS', 'expression' => 10],
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $campaignIds = collect($response->json('rows'))->pluck('campaign_id');

    expect($campaignIds->all())->toBe([10]);
});

it('filters reports.build by a datetime range', function () {
    makeReportClick(20, ['datetime' => now()->subDays(10)]);
    $recent = makeReportClick(20, ['datetime' => now()->subMinutes(1)]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['click_id', 'campaign_id'],
        'range' => [
            'from' => now()->subHour()->toDateTimeString(),
            'to' => now()->toDateTimeString(),
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $clickIds = collect($response->json('rows'))->pluck('click_id');

    expect($clickIds->all())->toBe([$recent->click_id]);
});

it('sorts reports.build rows descending by a metric', function () {
    makeReportClick(30, ['cost' => 5]);
    makeReportClick(31, ['cost' => 1]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['campaign_id', 'cost'],
        'grouping' => ['campaign_id'],
        'sort' => [['name' => 'cost', 'order' => 'DESC']],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $campaignIds = collect($response->json('rows'))->pluck('campaign_id');

    expect($campaignIds->all())->toBe([30, 31]);
});
