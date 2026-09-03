<?php

use App\Models\Click;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

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

/*
|--------------------------------------------------------------------------
| geo/device/isp dimensions — GEO_DEVICE_JOINS (2026-09-03 addition, part 1.3)
|--------------------------------------------------------------------------
| No Visitor/RefCountry-style Eloquent models exist yet for these tables
| (only the migration, see 2025_01_01_000029_create_visitors_and_geo_
| device_ref_tables.php) — fixtures go straight through DB::table(), same
| "direct SQL fixture" convention used project-wide for not-yet-modeled
| tables.
*/
function makeGeoDeviceVisitor(array $refValues): int
{
    $refIds = [];
    foreach ($refValues as $table => $value) {
        $refIds[$table] = DB::table($table)->insertGetId(['value' => $value]);
    }

    $ipId = DB::table('ref_ips')->insertGetId(['value' => ip2long('203.0.113.'.random_int(1, 254))]);
    $uaId = DB::table('ref_user_agents')->insertGetId(['value' => 'Mozilla/5.0 (test-fixture; '.uniqid().')']);

    return DB::table('visitors')->insertGetId([
        'visitor_code' => 'geo-device-test-'.uniqid(),
        'ip_id' => $ipId,
        'user_agent_id' => $uaId,
        'country_id' => $refIds['ref_countries'] ?? null,
        'region_id' => $refIds['ref_regions'] ?? null,
        'city_id' => $refIds['ref_cities'] ?? null,
        'browser_id' => $refIds['ref_browsers'] ?? null,
        'browser_version_id' => $refIds['ref_browser_versions'] ?? null,
        'os_id' => $refIds['ref_os'] ?? null,
        'os_version_id' => $refIds['ref_os_versions'] ?? null,
        'device_type_id' => $refIds['ref_device_types'] ?? null,
        'device_model_id' => $refIds['ref_device_models'] ?? null,
        'isp_id' => $refIds['ref_isp'] ?? null,
        'operator_id' => $refIds['ref_operators'] ?? null,
        'connection_type_id' => $refIds['ref_connection_types'] ?? null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('resolves geo/device/isp dimensions on reports.build through the visitors join', function () {
    $visitorId = makeGeoDeviceVisitor([
        'ref_countries' => 'US',
        'ref_regions' => 'California',
        'ref_cities' => 'San Francisco',
        'ref_browsers' => 'Chrome',
        'ref_browser_versions' => '128.0',
        'ref_os' => 'Windows',
        'ref_os_versions' => '11',
        'ref_device_types' => 'desktop',
        'ref_device_models' => 'Generic PC',
        'ref_isp' => 'Comcast',
        'ref_operators' => 'N/A',
        'ref_connection_types' => 'broadband',
    ]);

    makeReportClick(40, ['visitor_id' => $visitorId, 'cost' => 1]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['campaign_id', 'country', 'region', 'city', 'browser', 'browser_version', 'os', 'os_version', 'device_type', 'device_model', 'isp', 'operator', 'connection_type'],
        'filters' => [
            ['name' => 'campaign_id', 'operator' => 'EQUALS', 'expression' => 40],
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $row = $response->json('rows')[0];

    expect($row['country'])->toBe('US');
    expect($row['region'])->toBe('California');
    expect($row['city'])->toBe('San Francisco');
    expect($row['browser'])->toBe('Chrome');
    expect($row['browser_version'])->toBe('128.0');
    expect($row['os'])->toBe('Windows');
    expect($row['os_version'])->toBe('11');
    expect($row['device_type'])->toBe('desktop');
    expect($row['device_model'])->toBe('Generic PC');
    expect($row['isp'])->toBe('Comcast');
    expect($row['operator'])->toBe('N/A');
    expect($row['connection_type'])->toBe('broadband');
});

it('leaves geo/device dimensions null for a click whose visitor has no matching ref rows (LEFT JOIN, not INNER)', function () {
    // No visitors row at all for this visitor_id — exercises the LEFT JOIN
    // path (a click must never disappear from reports.build just because
    // its visitor lookup is missing).
    makeReportClick(41, ['visitor_id' => 999999999, 'cost' => 1]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['campaign_id', 'country', 'clicks'],
        'grouping' => ['campaign_id', 'country'],
        'filters' => [
            ['name' => 'campaign_id', 'operator' => 'EQUALS', 'expression' => 41],
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $row = $response->json('rows')[0];

    expect($row['country'])->toBeNull();
    expect($row['clicks'])->toBe(1);
});

it('groups and filters reports.build by the country dimension', function () {
    $us = makeGeoDeviceVisitor(['ref_countries' => 'US']);
    $de = makeGeoDeviceVisitor(['ref_countries' => 'DE']);

    makeReportClick(42, ['visitor_id' => $us, 'cost' => 1]);
    makeReportClick(42, ['visitor_id' => $us, 'cost' => 1]);
    makeReportClick(42, ['visitor_id' => $de, 'cost' => 1]);

    $response = $this->postJson(reportsEndpoint('build'), [
        'columns' => ['country', 'clicks'],
        'grouping' => ['country'],
        'filters' => [
            ['name' => 'campaign_id', 'operator' => 'EQUALS', 'expression' => 42],
            ['name' => 'country', 'operator' => 'EQUALS', 'expression' => 'US'],
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $rows = $response->json('rows');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['country'])->toBe('US');
    expect($rows[0]['clicks'])->toBe(2);
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
