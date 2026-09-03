<?php

use App\Models\Conversion;
use App\Models\User;
use App\Services\AuthService;
use Database\Factories\UserFactory;

/*
|--------------------------------------------------------------------------
| conversions.log / conversions.logDefinition / conversions.statuses /
| conversions.import / conversions.updateCostDefinition
|--------------------------------------------------------------------------
|
| Exercises App\Http\Controllers\Admin\ConversionsController through the
| real ?object=conversions.<action> endpoints (App\Http\Controllers\
| ObjectDispatchController). `conversions.log` goes through
| App\Services\Grid\GridBuilder (used as-is, not modified) against the
| `conversions` table.
|
| The `conversions` table has no writer yet (the conversion/postback
| pipeline is not started) — rows are inserted directly via
| Conversion::create() here, per the task brief, to exercise the grid with
| real data.
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

/** Build the legacy dispatcher URL for a given `object=conversions.<action>`. */
function conversionsEndpoint(string $action, array $query = []): string
{
    $query = array_merge(['object' => "conversions.{$action}"], $query);

    return '/admin/index.php?'.http_build_query($query);
}

/** Makes LegacyAuthMiddleware resolve $user as the current user on the next request. */
function actingAsAdminForConversions(?User $user): void
{
    test()->mock(AuthService::class, function ($mock) use ($user) {
        $mock->shouldReceive('verifyFromCookie')->andReturn($user);
    });
}

beforeEach(function () {
    $admin = UserFactory::new()->admin()->create();
    actingAsAdminForConversions($admin);
});

/** Minimal valid `conversions` row, with per-row overrides. */
function makeConversionRow(int $campaignId, int $clickId, array $overrides = []): Conversion
{
    static $counter = 0;
    $counter++;

    return Conversion::create(array_merge([
        'campaign_id' => $campaignId,
        'click_id' => $clickId,
        'sub_id' => 'conv-sub-'.$campaignId.'-'.$counter,
        'click_datetime' => now()->subMinutes(10),
        'postback_datetime' => now()->subMinutes(5),
        'status' => 'sale',
        'revenue' => 0,
        'cost' => 0,
    ], $overrides));
}

it('returns the conversions logDefinition with the expected minimal column set', function () {
    $response = $this->getJson(conversionsEndpoint('logDefinition'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
    expect($data['url'])->toBe('?object=conversions.log');
    expect($data['details'])->toBe(['id' => 'conversion_id']);
    expect($data['range_intervals'])->toBeNull();

    $columnNames = collect($data['columns'])->pluck('name');
    foreach (['conversion_id', 'campaign_id', 'offer_id', 'landing_id', 'sub_id', 'status', 'revenue', 'cost', 'click_datetime', 'postback_datetime'] as $expected) {
        expect($columnNames)->toContain($expected);
    }

    $revenueColumn = collect($data['columns'])->firstWhere('name', 'revenue');
    expect($revenueColumn['metric'])->toBeTrue();
});

it('returns the conversion statuses dictionary matching legacy Conversion model constants', function () {
    $response = $this->getJson(conversionsEndpoint('statuses'));

    $response->assertStatus(200);
    $ids = collect($response->json())->pluck('id');

    expect($ids->all())->toBe(['lead', 'sale', 'rejected', 'rebill']);
});

it('groups conversions.log by conversion_id and computes revenue/cost/profit per row', function () {
    $conv1 = makeConversionRow(1, 100, ['status' => 'sale', 'revenue' => 20, 'cost' => 2]);
    $conv2 = makeConversionRow(1, 101, ['status' => 'lead', 'revenue' => 5, 'cost' => 1]);

    $response = $this->postJson(conversionsEndpoint('log'), [
        'columns' => ['conversion_id', 'campaign_id', 'status', 'revenue', 'cost', 'profit', 'profitability'],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['rows', 'total', 'summary', 'meta']);
    expect($data['total'])->toBe(2);

    $row1 = collect($data['rows'])->firstWhere('conversion_id', $conv1->conversion_id);
    expect($row1)->not->toBeNull();
    expect((float) $row1['revenue'])->toBe(20.0);
    expect((float) $row1['cost'])->toBe(2.0);
    expect((float) $row1['profit'])->toBe(18.0);
    expect((float) $row1['profitability'])->toBe(18.0);

    $row2 = collect($data['rows'])->firstWhere('conversion_id', $conv2->conversion_id);
    expect((float) $row2['revenue'])->toBe(5.0);
    expect((float) $row2['cost'])->toBe(1.0);
    expect((float) $row2['profit'])->toBe(4.0);
});

it('forces grouping by conversion_id regardless of the requested `grouping` param', function () {
    makeConversionRow(1, 100, ['status' => 'sale', 'revenue' => 10, 'cost' => 1]);
    makeConversionRow(1, 101, ['status' => 'sale', 'revenue' => 30, 'cost' => 3]);

    // Legacy `ConversionRepository::log()` unconditionally overwrites
    // "grouping" to ["conversion_id"] — requesting a group-by-campaign_id
    // here must NOT collapse the two conversions into one summed row.
    $response = $this->postJson(conversionsEndpoint('log'), [
        'columns' => ['conversion_id', 'campaign_id', 'revenue'],
        'grouping' => ['campaign_id'],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    expect($response->json('total'))->toBe(2);
});

it('filters conversions.log by status', function () {
    makeConversionRow(1, 100, ['status' => 'sale']);
    makeConversionRow(1, 101, ['status' => 'lead']);
    makeConversionRow(1, 102, ['status' => 'rejected']);

    $response = $this->postJson(conversionsEndpoint('log'), [
        'columns' => ['conversion_id', 'status'],
        'filters' => [
            ['name' => 'status', 'operator' => 'EQUALS', 'expression' => 'lead'],
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $statuses = collect($response->json('rows'))->pluck('status');

    expect($statuses->all())->toBe(['lead']);
});

it('filters conversions.log by campaign_id with IN_LIST', function () {
    makeConversionRow(1, 100);
    makeConversionRow(2, 101);
    makeConversionRow(3, 102);

    $response = $this->postJson(conversionsEndpoint('log'), [
        'columns' => ['conversion_id', 'campaign_id'],
        'filters' => [
            ['name' => 'campaign_id', 'operator' => 'IN_LIST', 'expression' => [1, 2]],
        ],
        'limit' => 50,
    ]);

    $response->assertStatus(200);
    $campaignIds = collect($response->json('rows'))->pluck('campaign_id')->sort()->values();

    expect($campaignIds->all())->toBe([1, 2]);
});

it('returns 406 from conversions.import when data or currency is missing', function () {
    $response = $this->postJson(conversionsEndpoint('import'), []);

    $response->assertStatus(406);
    expect($response->json('error'))->toBe('Import data or currency is empty');
});

it('conversions.import: matches by sub_id, defaults to sale status with no status column, syncs click totals', function () {
    $click = \App\Models\Click::create([
        'visitor_id' => 6001, 'sub_id' => 'import-sub-1', 'datetime' => now(),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);

    $response = $this->postJson(conversionsEndpoint('import'), [
        'data' => 'import-sub-1,12.50',
        'currency' => 'USD',
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['errors' => [], 'success' => 1, 'total' => 1]);

    $conversion = \App\Models\Conversion::where('sub_id', 'import-sub-1')->first();
    expect($conversion)->not->toBeNull();
    expect((float) $conversion->revenue)->toBe(12.5);
    expect($conversion->status)->toBe('sale');
    expect($conversion->original_status)->toBeNull();

    $click->refresh();
    expect($click->is_sale)->toBeTrue();
    expect((float) $click->sale_revenue)->toBe(12.5);
});

it('conversions.import: recognized status variation maps correctly, unrecognized falls back to lead', function () {
    \App\Models\Click::create([
        'visitor_id' => 6002, 'sub_id' => 'import-sub-2', 'datetime' => now(),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);
    \App\Models\Click::create([
        'visitor_id' => 6003, 'sub_id' => 'import-sub-3', 'datetime' => now(),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);

    $response = $this->postJson(conversionsEndpoint('import'), [
        'data' => "import-sub-2,5,,confirmed\nimport-sub-3,5,,gibberish_status",
        'currency' => 'USD',
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['errors' => [], 'success' => 2, 'total' => 2]);

    expect(\App\Models\Conversion::where('sub_id', 'import-sub-2')->first()->status)->toBe('sale');
    expect(\App\Models\Conversion::where('sub_id', 'import-sub-3')->first()->status)->toBe('lead');
});

it('conversions.import: unknown sub_id is reported as an error, prefixed with the sub_id', function () {
    $response = $this->postJson(conversionsEndpoint('import'), [
        'data' => 'no-such-sub-id,10',
        'currency' => 'USD',
    ]);

    $response->assertStatus(200);
    $body = $response->json();
    expect($body['success'])->toBe(0);
    expect($body['total'])->toBe(1);
    expect($body['errors'])->toBe(['no-such-sub-id: SubID not found "no-such-sub-id"']);
});

it('conversions.import: a malformed row (no comma) is silently dropped, not counted toward total', function () {
    $response = $this->postJson(conversionsEndpoint('import'), [
        'data' => "no-comma-here\nalso-malformed",
        'currency' => 'USD',
    ]);

    $response->assertStatus(200);
    expect($response->json())->toBe(['errors' => [], 'success' => 0, 'total' => 0]);
});

it('returns a real ClicksDefinition-shaped grid definition from conversions.updateCostDefinition', function () {
    $response = $this->getJson(conversionsEndpoint('updateCostDefinition'));

    $response->assertStatus(200);
    $data = $response->json();

    expect($data)->toHaveKeys(['url', 'details', 'range_intervals', 'columns']);
    expect($data['url'])->toBeNull();
    expect($data['details'])->toBeNull();
    expect($data['range_intervals'])->toBe([]);

    $names = collect($data['columns'])->pluck('name');
    expect($names)->toContain('click_id', 'campaign_id', 'stream_id', 'revenue', 'cost', 'profit', 'sub_id_1', 'sub_id_15', 'extra_param_1');

    $revenue = collect($data['columns'])->firstWhere('name', 'revenue');
    expect($revenue['metric'])->toBeTrue();
});
