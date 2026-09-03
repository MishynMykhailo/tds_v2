<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Services\AclService;
use App\Services\CurrentUserService;
use App\Services\Grid\GridBuilder;
use App\Services\Grid\QueryParams;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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
 * `build`/`definition`/`summary`/`columnsAsOptions`/`parameterAliases`/
 * `statsForCampaign` — the full legacy action set — are implemented (the
 * last 4 were added 2026-09-03, during a session-wide controller audit
 * that found them missing: a prior docblock here said they were "not
 * asked for this round", which was true at the time but had gone stale —
 * closing them for exhaustive backend/legacy contract parity).
 * `favouriteReport`/`exportedReports`/`labels` are separate `?object=`
 * controllers entirely (FavouriteReportController/ExportedReportsController/
 * LabelsController) and out of scope here regardless.
 */
class ReportsController extends Controller
{
    public function __construct(
        private readonly AclService $aclService,
        private readonly CurrentUserService $currentUserService,
    ) {}

    // ---------------------------------------------------------------
    // Legacy param-reading / §6 error-shape helpers — duplicated from
    // CampaignsController per this codebase's established per-controller
    // convention.
    // ---------------------------------------------------------------

    private function param(Request $request, string $name, $default = null)
    {
        if ($request->query->has($name)) {
            return $request->query->get($name);
        }

        $body = $request->request->all();
        if (array_key_exists($name, $body)) {
            return $body[$name];
        }

        return $default;
    }

    private function notFound(string $message = 'Not found'): Response
    {
        return response()->json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 404);
    }

    private function forbidden(string $message = 'Access denied'): Response
    {
        return response()->json(['error' => $message], 403);
    }

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
     * geo/device/isp dimensions (country/region/city/browser/
     * browser_version/os/os_version/device_type/device_model/isp/operator/
     * connection_type) ARE included below — see GEO_DEVICE_JOINS + the
     * `App\Services\Grid\GridBuilder` `$joins` param (added for this).
     * CORRECTION (2026-09-03): an earlier version of this docblock claimed
     * these were left out because "a join pattern already exists for
     * ref_sources/ref_referrers/ref_keywords, just not extended to geo/
     * device" — that was WRONG, verified by grepping the codebase: no
     * `GridBuilder` caller had ever joined onto any `ref_*` table before
     * this round; `GridBuilder` was genuinely single-table only. Fixed by
     * adding a generic `$joins` param to `GridBuilder` (LEFT JOINs applied
     * to every query it runs — main select, total count, summary) rather
     * than special-casing this controller.
     *
     * NOT included, and why:
     * - referrer/search_engine/keyword/source/ad_campaign_id/external_id/
     *   creative_id/x_requested_with/destination NAME columns, language,
     *   ip, user_agent, campaign/offer/landing/stream/ts NAME columns —
     *   out of scope for this round (task brief only asked for geo/device/
     *   isp). The raw `*_id` FK columns for these ARE already included
     *   below (they're real `clicks` columns). `ip`/`user_agent` need a
     *   MySQL-only unpack function (`INET_NTOA`, packed-int storage) that
     *   doesn't exist under SQLite (this suite's test driver, see
     *   `calendar grouping dimensions` note below) — left out for the same
     *   cross-driver reason as calendar columns, not forgotten.
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
    /**
     * LEFT JOINs feeding the geo/device/isp columns in BUILD_COLUMNS_BASE:
     * `clicks.visitor_id` -> `visitors` -> each geo/device `ref_*`
     * dictionary table (see database/migrations/
     * 2025_01_01_000029_create_visitors_and_geo_device_ref_tables.php for
     * the schema this mirrors — all FKs on `visitors` are nullable, hence
     * LEFT not INNER, so a click with no matched visitor row still returns
     * with null geo/device values instead of being dropped).
     */
    private const GEO_DEVICE_JOINS = [
        ['visitors', 'clicks.visitor_id', '=', 'visitors.id'],
        ['ref_countries', 'visitors.country_id', '=', 'ref_countries.id'],
        ['ref_regions', 'visitors.region_id', '=', 'ref_regions.id'],
        ['ref_cities', 'visitors.city_id', '=', 'ref_cities.id'],
        ['ref_browsers', 'visitors.browser_id', '=', 'ref_browsers.id'],
        ['ref_browser_versions', 'visitors.browser_version_id', '=', 'ref_browser_versions.id'],
        ['ref_os', 'visitors.os_id', '=', 'ref_os.id'],
        ['ref_os_versions', 'visitors.os_version_id', '=', 'ref_os_versions.id'],
        ['ref_device_types', 'visitors.device_type_id', '=', 'ref_device_types.id'],
        ['ref_device_models', 'visitors.device_model_id', '=', 'ref_device_models.id'],
        ['ref_isp', 'visitors.isp_id', '=', 'ref_isp.id'],
        ['ref_operators', 'visitors.operator_id', '=', 'ref_operators.id'],
        ['ref_connection_types', 'visitors.connection_type_id', '=', 'ref_connection_types.id'],
    ];

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
        // geo/device/isp — GEO_DEVICE_JOINS above resolves these through
        // clicks.visitor_id -> visitors -> the matching ref_* table.
        'country' => 'ref_countries.value',
        'region' => 'ref_regions.value',
        'city' => 'ref_cities.value',
        'browser' => 'ref_browsers.value',
        'browser_version' => 'ref_browser_versions.value',
        'os' => 'ref_os.value',
        'os_version' => 'ref_os_versions.value',
        'device_type' => 'ref_device_types.value',
        'device_model' => 'ref_device_models.value',
        'isp' => 'ref_isp.value',
        'operator' => 'ref_operators.value',
        'connection_type' => 'ref_connection_types.value',
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
     * ACL IS applied (see App\Services\Grid\GridBuilder::baseQuery()) via
     * the same `campaign_id IN (...)` restriction as the rest of this
     * codebase's Grid-backed endpoints — the stale "ACL not applied" note
     * that used to sit here was corrected alongside the withStatsAction
     * ACL docblock cleanup (see docs/PORTING_LOG.md, GridAclTest.php).
     */
    public function buildAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);

        $builder = new GridBuilder('clicks', self::buildColumns(), $this->currentUserService->get(), self::GEO_DEVICE_JOINS);

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
        return [
            'url' => '?object=reports.build',
            'details' => null,
            'range_intervals' => null,
            'columns' => self::columnDefinitions(),
        ];
    }

    /**
     * The `definitionAction()` column list, extracted so
     * `columnsAsOptionsAction()` (legacy `GridDefinition::listAsOptions()`)
     * can reuse the exact same set instead of duplicating it.
     */
    private static function columnDefinitions(): array
    {
        $extraParamColumns = [];
        for ($i = 1; $i <= 10; $i++) {
            $extraParamColumns[] = ['name' => "extra_param_{$i}", 'type' => 'string', 'category' => 'params', 'filter' => ['type' => 'string']];
        }

        return [
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
                ['name' => 'country', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'geo', 'width' => 100],
                ['name' => 'region', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'geo', 'width' => 100],
                ['name' => 'city', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'geo', 'width' => 100],
                ['name' => 'browser', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'device', 'width' => 100],
                ['name' => 'browser_version', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'device', 'width' => 100],
                ['name' => 'os', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'device', 'width' => 100],
                ['name' => 'os_version', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'device', 'width' => 100],
                ['name' => 'device_type', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'device', 'width' => 100],
                ['name' => 'device_model', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'device', 'width' => 100],
                ['name' => 'isp', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'geo', 'width' => 100],
                ['name' => 'operator', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'geo', 'width' => 100],
                ['name' => 'connection_type', 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'geo', 'width' => 100],
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
        ];
    }

    /**
     * Legacy `reports.summary` -> `ClickRepository::summary()` ->
     * `GridBuilder::factory(...)->getSummary()` — same pipeline as
     * `buildAction()`, but only the totals row, always computed regardless
     * of the request's own `summary` flag (legacy's `summary()` method has
     * no such flag at all — this endpoint's entire purpose IS the summary).
     */
    public function summaryAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);
        $params->summary = true;

        $builder = new GridBuilder('clicks', self::buildColumns(), $this->currentUserService->get(), self::GEO_DEVICE_JOINS);
        $result = $builder->build($params);

        return $result['summary'] ?? [];
    }

    /**
     * Legacy `reports.columnsAsOptions` -> `GridDefinition::listAsOptions()`
     * — every non-hidden column as `{category, name, value}` (legacy
     * translates `category`/`name` via `LocaleService`; this port has no
     * i18n layer at all, per established precedent elsewhere in this
     * codebase — `category` is the raw category string, `name` is the
     * column's own `th_title`/`name` humanized, matching the "hardcode
     * English, no i18n" convention already used for e.g. Campaigns'
     * ungrouped-group "Default" fallback).
     */
    public function columnsAsOptionsAction(Request $request): array
    {
        $options = [];

        foreach (self::columnDefinitions() as $column) {
            if (! empty($column['hidden'])) {
                continue;
            }

            $label = $column['th_title'] ?? $column['name'];
            $options[] = [
                'category' => $column['category'] ?? '',
                'name' => ucfirst(str_replace(['grid.', '_th', '_'], ['', '', ' '], $label)),
                'value' => $column['name'],
            ];
        }

        return $options;
    }

    /**
     * Legacy `reports.parameterAliases` ->
     * `CampaignRepository::getParameterAliases($campaign)` ->
     * `TrafficSourceService::getAliasForParameter()`. For each of the
     * campaign's tracking `parameters` (JSON map keyed by e.g.
     * `sub_id_1`/`extra_param_3`, each optionally carrying an `alias`)
     * that actually has a non-empty alias set, returns
     * `{parameter, alias}` with the same `[S1]`/`[X3]` prefix legacy adds
     * for `sub_id_N`/`extra_param_N` parameter names.
     */
    public function parameterAliasesAction(Request $request): Response|array
    {
        $campaignId = (int) $this->param($request, 'campaign_id');
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            // Exact legacy message (Core\Db\DataRepository::findRaw() ->
            // NotFoundError), found live 2026-09-03 - same class of fix
            // already applied to Labels/GeoProfiles this session (a
            // generic "not found" doesn't match legacy's actual body).
            return $this->notFound("Traffic\\Model\\Campaign #{$campaignId} not found");
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign');
        }

        $parameters = $campaign->parameters ?? [];
        $result = [];

        foreach ($parameters as $name => $data) {
            $alias = is_array($data) ? ($data['alias'] ?? null) : null;
            if (empty($alias)) {
                continue;
            }

            $prefix = '';
            if (preg_match('/sub_id_([0-9]+)/', (string) $name, $m)) {
                $prefix = '[S'.$m[1].'] ';
            } elseif (preg_match('/extra_param_([0-9]+)/', (string) $name, $m)) {
                $prefix = '[X'.$m[1].'] ';
            }

            $result[] = ['parameter' => $name, 'alias' => $prefix.$alias];
        }

        return $result;
    }

    /**
     * Legacy `reports.statsForCampaign` ->
     * `ReportRepository::briefCampaignStats()` — a fixed report (metrics
     * clicks/stream_unique_clicks/bots, grouped by stream_id, filtered to
     * one campaign, over `?range=`) reshaped into an object keyed by
     * stream_id (legacy uses a `stdClass` with dynamic properties for
     * exactly this reason — JSON-encodes as `{"3": {...}, "7": {...}}`,
     * not an array). Empty result -> `{"null": {zeros}}`, matching
     * legacy's literal `$result->null = [...]` fallback.
     */
    public function statsForCampaignAction(Request $request): Response|\stdClass
    {
        $campaignId = (int) $this->param($request, 'campaign_id');
        $campaign = Campaign::find($campaignId);

        if (! $campaign) {
            // Exact legacy message (Core\Db\DataRepository::findRaw() ->
            // NotFoundError), found live 2026-09-03 - same class of fix
            // already applied to Labels/GeoProfiles this session (a
            // generic "not found" doesn't match legacy's actual body).
            return $this->notFound("Traffic\\Model\\Campaign #{$campaignId} not found");
        }

        if (! $this->aclService->isViewAllowed($this->currentUserService->get(), $campaign)) {
            return $this->forbidden('You are not allowed to view this campaign');
        }

        $params = new QueryParams();
        $params->metrics = ['clicks', 'stream_unique_clicks', 'bots'];
        $params->grouping = ['stream_id'];
        // Explicit `columns` (grouping + metrics only, mirroring legacy
        // `QueryParams::_columns = array_merge($_columns, $_grouping,
        // $_metrics)` when `columns` itself is omitted) — GridBuilder's
        // implicit "no columns -> select everything" default would mix
        // every OTHER raw per-row column into this GROUP BY stream_id
        // query, none of them functionally dependent on stream_id, which
        // 500s under real MySQL's ONLY_FULL_GROUP_BY (found live fixing
        // this same class of bug for reports.build/summary above).
        $params->columns = ['stream_id', 'clicks', 'stream_unique_clicks', 'bots'];
        $params->filters = [['name' => 'campaign_id', 'operator' => 'equals', 'expression' => $campaign->id]];
        $range = $this->param($request, 'range');
        $params->range = is_array($range) ? $range : null;

        $builder = new GridBuilder('clicks', self::buildColumns(), $this->currentUserService->get(), self::GEO_DEVICE_JOINS);
        $rows = $builder->build($params)['rows'] ?? [];

        $result = new \stdClass();

        if (empty($rows)) {
            $result->null = ['clicks' => 0, 'stream_unique_clicks' => 0, 'bots' => 0];

            return $result;
        }

        foreach ($rows as $row) {
            if (! empty($row['stream_id'])) {
                $result->{$row['stream_id']} = [
                    'clicks' => (int) ($row['clicks'] ?? 0),
                    'stream_unique_clicks' => (int) ($row['stream_unique_clicks'] ?? 0),
                    'bots' => (int) ($row['bots'] ?? 0),
                ];
            }
        }

        return $result;
    }
}
