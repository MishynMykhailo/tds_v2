<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CurrentUserService;
use App\Services\Grid\GridBuilder;
use App\Services\Grid\QueryParams;
use Illuminate\Http\Request;

/**
 * Compatibility port of the legacy
 * `Component\Reports\Controller\ReportsController` (old codebase:
 * application/Component/Reports/Controller/ReportsController.php), backed
 * by `Component\Reports\Grid\ReportDefinition` (extends
 * `Component\Clicks\Grid\ClicksDefinition`).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.10_reports_grid.md,
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §9.
 *
 * Only `build` (§9.3, "the main report builder") and `definition` are
 * implemented this round, per the task brief. `summary`/`columnsAsOptions`/
 * `parameterAliases`/`statsForCampaign` are other real ReportsController
 * actions in the legacy source but were not asked for this round and are
 * intentionally left unimplemented (ObjectDispatchController 404s them,
 * same as every other not-yet-ported action in this codebase).
 * `favouriteReport`/`exportedReports`/`labels` are separate `?object=`
 * controllers entirely (FavouriteReportController/ExportedReportsController/
 * LabelsController) and out of scope here regardless.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    /**
     * `reports.build` column/metric whitelist (logical name -> raw SQL
     * expression against the `clicks` table), ported from
     * `ReportDefinition::initColumns()` + the `ClicksDefinition::
     * initColumns()` base it extends (application/Component/Reports/Grid/
     * ReportDefinition.php, application/Component/Clicks/Grid/
     * ClicksDefinition.php), restricted to a realistic subset that maps to
     * columns that actually exist on this Laravel port's `clicks` table
     * (database/migrations/2025_01_01_000018_create_clicks_table.php).
     *
     * NOT included, and why:
     * - geo/device/browser/ISP/referrer dimensions (country, city, os,
     *   browser, isp, language, ip, region, user_agent, operator,
     *   referrer, search_engine, keyword, ad_campaign_id, external_id,
     *   creative_id, source, x_requested_with, campaign/offer/landing/
     *   stream/ts NAME columns) — all of these are SQL JOINs onto
     *   `visitors`/`campaigns`/`offers`/`ref_*` dictionary tables in the
     *   real schema (ClicksDefinition::initRelations()).
     *   `App\Services\Grid\GridBuilder` (used as-is per the task brief, not
     *   modified) only ever queries a single table with no join support,
     *   so these would be fabricated columns. The raw `*_id` FK columns for
     *   most of these ARE included below since they're real `clicks`
     *   columns (country_id/os_id/etc. are NOT, though — those live on
     *   `visitors`, not `clicks`, so even the FK id isn't on this table).
     * - sub_id_1..15 — real column is `sub_id_N_id` (FK to a dictionary,
     *   not the dereferenced text ReportDefinition exposes under
     *   "sub_id_N") — same single-table/no-join limitation, omitted rather
     *   than exposed under a misleading name.
     * - calendar grouping dimensions (year/month/week/weekday/day/hour/
     *   day_hour) — legacy builds these with MySQL-only functions
     *   (DATE_FORMAT/CONVERT_TZ/YEARWEEK/WEEKDAY). This whitelist is
     *   queried through both MySQL (prod) and SQLite (Pest suite, see
     *   tests/Pest.php RefreshDatabase + phpunit.xml
     *   DB_CONNECTION=sqlite) via the same GridBuilder with no per-driver
     *   branching, so these are omitted rather than breaking under SQLite.
     * - ratio metrics requiring legacy's two-stage inner_select/
     *   outer_select split for a handful of columns whose formula needs
     *   OTHER outer_select metrics as inputs (uc_*_rate, crs, crl,
     *   roi_confirmed, epc_confirmed, ec, ec_confirmed, ecpm*, cps) —
     *   `GridBuilder` computes every selected column in one pass with no
     *   such staging. A small, self-contained subset of ratio metrics
     *   (cr/roi/epc/cpc/cpa) IS included below since each is directly
     *   expressible as one aggregate SQL expression.
     */
    private const BUILD_COLUMNS_BASE = [
        // ids / dimensions
        'click_id' => 'click_id',
        'visitor_id' => 'visitor_id',
        'sub_id' => 'sub_id',
        'datetime' => 'datetime',
        'campaign_id' => 'campaign_id',
        'parent_campaign_id' => 'parent_campaign_id',
        'stream_id' => 'stream_id',
        'ts_id' => 'ts_id',
        'landing_id' => 'landing_id',
        'landing_clicked' => 'landing_clicked',
        'landing_clicked_datetime' => 'landing_clicked_datetime',
        'offer_id' => 'offer_id',
        'affiliate_network_id' => 'affiliate_network_id',
        'source_id' => 'source_id',
        'referrer_id' => 'referrer_id',
        'search_engine_id' => 'search_engine_id',
        'keyword_id' => 'keyword_id',
        'destination_id' => 'destination_id',
        'creative_id_id' => 'creative_id_id',
        'external_id_id' => 'external_id_id',
        'ad_campaign_id_id' => 'ad_campaign_id_id',
        'x_requested_with_id' => 'x_requested_with_id',
        // booleans / flags
        'is_unique_stream' => 'is_unique_stream',
        'is_unique_campaign' => 'is_unique_campaign',
        'is_unique_global' => 'is_unique_global',
        'is_bot' => 'is_bot',
        'is_using_proxy' => 'is_using_proxy',
        'is_empty_referrer' => 'is_empty_referrer',
        'is_lead' => 'is_lead',
        'is_sale' => 'is_sale',
        'is_rejected' => 'is_rejected',
        // volume metrics — ReportDefinition.php inner_select, verified 1:1
        // (same expressions already used by App\Services\Grid\
        // EntityGridBuilder::METRIC_EXPRESSIONS for clicks/conversions/
        // leads/sales/rejected/revenue/cost/profit).
        'clicks' => 'COUNT(click_id)',
        'campaign_unique_clicks' => 'SUM(is_unique_campaign)',
        'stream_unique_clicks' => 'SUM(is_unique_stream)',
        'global_unique_clicks' => 'SUM(is_unique_global)',
        'bots' => 'SUM(is_bot)',
        'proxies' => 'SUM(is_using_proxy)',
        'empty_referrers' => 'SUM(is_empty_referrer)',
        'conversions' => '(SUM(is_sale) + SUM(is_lead) + SUM(is_rejected))',
        'leads' => 'SUM(is_lead)',
        'sales' => 'SUM(is_sale)',
        'rejected' => 'SUM(is_rejected)',
        'rebills' => 'SUM(rebills)',
        'lp_clicks' => 'SUM(landing_clicked)',
        // money metrics
        'revenue' => 'SUM(lead_revenue) + SUM(sale_revenue)',
        'lead_revenue' => 'SUM(lead_revenue)',
        'sale_revenue' => 'SUM(sale_revenue)',
        'rejected_revenue' => 'SUM(rejected_revenue)',
        'cost' => 'SUM(cost)',
        'profit' => 'SUM(lead_revenue) + SUM(sale_revenue) - SUM(cost)',
        // single-pass ratio metrics (see class docblock for which ratio
        // metrics were left out and why).
        'cr' => '(SUM(is_lead) + SUM(is_sale) + SUM(is_rejected)) * 100.0 / COUNT(click_id)',
        'roi' => '(SUM(lead_revenue) + SUM(sale_revenue) - SUM(cost)) * 100.0 / SUM(cost)',
        'epc' => '(SUM(lead_revenue) + SUM(sale_revenue)) / COUNT(click_id)',
        'cpc' => 'SUM(cost) / COUNT(click_id)',
        'cpa' => 'SUM(cost) / (SUM(is_sale) + SUM(is_lead) + SUM(is_rejected))',
    ];

    /** @return array<string, string> BUILD_COLUMNS_BASE plus extra_param_1..10 (real `clicks` table columns, ClicksDefinition::initColumns() loop). */
    private static function buildColumns(): array
    {
        $columns = self::BUILD_COLUMNS_BASE;
        for ($i = 1; $i <= 10; $i++) {
            $columns["extra_param_{$i}"] = "extra_param_{$i}";
        }

        return $columns;
    }

    /**
     * Legacy `reports.build` -> `ReportRepository::get()` ->
     * `GridBuilder::factory($queryParams, $userParams)->build()` (§9.3).
     *
     * ACL (legacy `AccessRestriction`/`DeletedCampaignRestriction`, §9.3)
     * is NOT applied — `App\Services\Grid\GridBuilder` has no ACL hook,
     * same TODO already called out for the other Grid-backed endpoints in
     * this codebase (see EntityGridBuilder docblocks).
     */
    public function buildAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new GridBuilder('clicks', self::buildColumns(), $this->currentUserService->get());

        return $builder->build($params);
    }

    /**
     * Legacy `reports.definition` -> `new ReportDefinition()
     * ->getGridDefinition()`. `range_intervals: null` mirrors
     * `ReportDefinition::$_rangeIntervals = NULL` (application/Component/
     * Reports/Grid/ReportDefinition.php:12, overriding the GridDefinition
     * base's `[]` default). `details: null` — ReportDefinition does not
     * override `$_details` (base GridDefinition default).
     *
     * Column set: every column in self::buildColumns() that maps to a real
     * `clicks` table field or a directly-expressible aggregate metric — see
     * that constant's docblock for what's deliberately excluded and why.
     */
    public function definitionAction(Request $request): array
    {
        $extraParamColumns = [];
        for ($i = 1; $i <= 10; $i++) {
            $extraParamColumns[] = ['name' => "extra_param_{$i}", 'type' => 'string', 'category' => 'params', 'filter' => ['type' => 'string']];
        }

        return [
            'url' => '?object=reports.build',
            'details' => null,
            'range_intervals' => null,
            'columns' => [
                ['name' => 'datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'category' => 'data', 'width' => 160],
                ['name' => 'click_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'visitor_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'sub_id', 'type' => 'string', 'sortable' => true, 'filter' => ['type' => 'string'], 'category' => 'ids', 'width' => 145],
                ['name' => 'campaign_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.campaign', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=campaigns.listAsOptions', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'parent_campaign_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.parent_campaign', 'category' => 'data', 'dictionary' => ['url' => '?object=campaigns.listAsOptions', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'offer_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.offer', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=offers.index&withGroupName=true', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'landing_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.landing', 'category' => 'data', 'dictionary' => ['url' => '?object=landings.index&withGroupName=true', 'valueProp' => 'id', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'affiliate_network_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.affiliate_network', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=affiliateNetworks.index', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'ts_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.ts', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=trafficSources.index']], 'category' => 'ids', 'width' => 80],
                ['name' => 'stream_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.stream', 'category' => 'data', 'dictionary' => ['url' => '?object=streams.listAsOptions', 'valueProp' => 'id', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'landing_clicked', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'data'],
                ['name' => 'landing_clicked_datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'category' => 'data'],
                ['name' => 'is_unique_stream', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'data'],
                ['name' => 'is_unique_campaign', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'data'],
                ['name' => 'is_unique_global', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'data'],
                ['name' => 'is_bot', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'device'],
                ['name' => 'is_using_proxy', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'geo'],
                ['name' => 'is_empty_referrer', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'data'],
                ['name' => 'is_lead', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'money'],
                ['name' => 'is_sale', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'money'],
                ['name' => 'is_rejected', 'type' => 'boolean', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'boolean'], 'formatter' => 'boolean', 'category' => 'money'],
                ['name' => 'source_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'referrer_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'search_engine_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'keyword_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'destination_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'creative_id_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'external_id_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'ad_campaign_id_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'x_requested_with_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ...$extraParamColumns,
                // volume metrics
                ['name' => 'clicks', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 52],
                ['name' => 'campaign_unique_clicks', 'type' => 'integer', 'th_title' => 'grid.campaign_unique_clicks_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'stream_unique_clicks', 'type' => 'integer', 'th_title' => 'grid.stream_unique_clicks_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'global_unique_clicks', 'type' => 'integer', 'th_title' => 'grid.global_unique_clicks_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'bots', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'proxies', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'empty_referrers', 'type' => 'integer', 'th_title' => 'grid.empty_referrers_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'conversions', 'type' => 'integer', 'th_title' => 'grid.conversions_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 50],
                ['name' => 'leads', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'sales', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'rejected', 'type' => 'integer', 'th_title' => 'grid.rejected_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'rebills', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                ['name' => 'lp_clicks', 'type' => 'integer', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'integer', 'width' => 80],
                // money metrics
                ['name' => 'revenue', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 80],
                ['name' => 'lead_revenue', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 80],
                ['name' => 'sale_revenue', 'type' => 'decimal', 'th_title' => 'grid.sale_revenue_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 80],
                ['name' => 'rejected_revenue', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 80],
                ['name' => 'cost', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 2, 'width' => 70],
                ['name' => 'profit', 'type' => 'decimal', 'th_title' => 'grid.profit_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'formatter' => 'money', 'fraction_size' => 2, 'width' => 70],
                // ratio metrics
                ['name' => 'cr', 'type' => 'decimal', 'th_title' => 'grid.cr_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'percentage', 'fraction_size' => 2, 'width' => 72],
                ['name' => 'roi', 'type' => 'decimal', 'th_title' => 'grid.roi_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'percentage_h', 'fraction_size' => 2, 'width' => 70],
                ['name' => 'epc', 'type' => 'decimal', 'th_title' => 'grid.epc_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 86],
                ['name' => 'cpc', 'type' => 'decimal', 'th_title' => 'grid.cpc_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 70],
                ['name' => 'cpa', 'type' => 'decimal', 'th_title' => 'grid.cpa_th', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'metrics', 'formatter' => 'money', 'fraction_size' => 4, 'width' => 80],
            ],
        ];
    }
}
