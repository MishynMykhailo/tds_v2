<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ConversionImportService;
use App\Services\CurrentUserService;
use App\Services\Grid\GridBuilder;
use App\Services\Grid\QueryParams;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Compatibility port of the legacy
 * `Component\Conversions\Controller\ConversionsController` (old codebase:
 * application/Component/Conversions/Controller/ConversionsController.php),
 * backed by `Component\Conversions\Grid\ConversionsLogDefinition` (extends
 * `Component\Clicks\Grid\ClickLogDefinition` extends `ClicksDefinition`) and
 * `Traffic\Model\Conversion` (statuses).
 *
 * Contract reference: docs/legacy-reference/frontend/api/10.11_conversions.md,
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §9.
 *
 * `log`, `logDefinition`, `statuses`, and now `import` (see
 * `App\Services\ConversionImportService`) are backed by real logic —
 * `updateCostDefinition` remains a documented TODO stub (see its own
 * docblock for why).
 */
class ConversionsController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
    ) {}

    /**
     * Legacy conversion status constants (`Traffic\Model\Conversion::LEAD` /
     * `SALE` / `REJECTED` / `REBILL`, confirmed by reading
     * application/Traffic/Model/Conversion.php:14-17 directly — NOT a
     * guess). Order matches `ConversionRepository::getStatuses()`.
     */
    private const STATUSES = ['lead', 'sale', 'rejected', 'rebill'];

    /**
     * `conversions.log` column whitelist (logical name -> raw SQL
     * expression against the `conversions` table), ported from
     * `ConversionsLogDefinition::initColumns()` (application/Component/
     * Conversions/Grid/ConversionsLogDefinition.php) restricted to columns
     * that actually exist on this Laravel port's `conversions` table
     * (database/migrations/2025_01_01_000019_create_conversions_table.php).
     *
     * NOT included, and why:
     * - dereferenced dictionary text columns the real definition inherits
     *   from ClicksDefinition (visitor_code, country, os, browser, isp,
     *   language, device_type, ip, region, city, user_agent, operator,
     *   referrer, search_engine, keyword, ad_campaign_id, external_id,
     *   creative_id, source, x_requested_with) — these all come from SQL
     *   JOINs onto `visitors`/`ref_*` dictionary tables in the real schema
     *   (see ClicksDefinition::initRelations()). `App\Services\Grid\
     *   GridBuilder` (used as-is per the task brief, not modified) only
     *   ever queries a single table with no join support, so exposing
     *   these under their legacy dereferenced names would be fabricating
     *   columns that don't exist on `conversions`. The raw `*_id` FK
     *   columns for these (source_id, referrer_id, search_engine_id,
     *   keyword_id) ARE included below since they're real columns.
     * - sub_id_1..15 — on this schema these are `sub_id_N_id` FK integer
     *   columns (dictionary references), not the dereferenced text values
     *   ConversionsLogDefinition exposes under "sub_id_N" — same
     *   single-table/no-join limitation as above. Omitted rather than
     *   exposed under a misleading name.
     * - `sale_period` (legacy: "UNIX_TIMESTAMP(sale_datetime) -
     *   UNIX_TIMESTAMP(click_datetime)") — MySQL-only function, and this
     *   whitelist is queried through both MySQL (prod) and SQLite (Pest
     *   suite, see tests/Pest.php RefreshDatabase + phpunit.xml
     *   DB_CONNECTION=sqlite) via the same GridBuilder with no per-driver
     *   branching. Omitted rather than breaking under SQLite.
     */
    private const LOG_COLUMNS_BASE = [
        'conversion_id' => 'conversion_id',
        'visitor_id' => 'visitor_id',
        'campaign_id' => 'campaign_id',
        'stream_id' => 'stream_id',
        'ts_id' => 'ts_id',
        'landing_id' => 'landing_id',
        'offer_id' => 'offer_id',
        'affiliate_network_id' => 'affiliate_network_id',
        'sub_id' => 'sub_id',
        'click_id' => 'click_id',
        'tid' => 'tid',
        'click_datetime' => 'click_datetime',
        'postback_datetime' => 'postback_datetime',
        'sale_datetime' => 'sale_datetime',
        'status' => 'status',
        'previous_status' => 'previous_status',
        'original_status' => 'original_status',
        'params' => 'params',
        'is_processed' => 'is_processed',
        'source_id' => 'source_id',
        'referrer_id' => 'referrer_id',
        'search_engine_id' => 'search_engine_id',
        'keyword_id' => 'keyword_id',
        // ConversionsLogDefinition::initColumns() overrides these to be
        // SUM(...)-based even though `grouping` is forced to
        // ["conversion_id"] (the table's primary key) below, so each group
        // is always exactly one row — SUM/plain value are equivalent here,
        // kept as SUM to match the real source's inner_select verbatim.
        'revenue' => 'SUM(revenue)',
        'cost' => 'SUM(cost)',
        // "SUM(`revenue`) - SUM(`cost`)" — ConversionsLogDefinition.php,
        // "profit" column.
        'profit' => 'SUM(revenue) - SUM(cost)',
        // "revenue - cost" (NOT summed, unlike profit above) — matches the
        // real "profitability" column's inner_select verbatim.
        'profitability' => 'revenue - cost',
    ];

    /** @return array<string, string> LOG_COLUMNS_BASE plus extra_param_1..10 (real `conversions` table columns, ClicksDefinition::initColumns() loop). */
    private static function logColumns(): array
    {
        $columns = self::LOG_COLUMNS_BASE;
        for ($i = 1; $i <= 10; $i++) {
            $columns["extra_param_{$i}"] = "extra_param_{$i}";
        }

        return $columns;
    }

    /**
     * Legacy `conversions.log` -> `ConversionRepository::log()` ->
     * `GridBuilder::factory($queryParams, $userParams)->build()`.
     *
     * `ConversionRepository::log()` unconditionally overwrites
     * `$params["grouping"] = ["conversion_id"]` before constructing
     * QueryParams (application/Component/Conversions/Repository/
     * ConversionRepository.php) — replicated here by forcing `grouping`
     * on the parsed QueryParams the same way, ignoring whatever the
     * request sent.
     *
     * ACL (legacy `AccessRestriction`/`DeletedCampaignRestriction`, §9.3)
     * is NOT applied — `App\Services\Grid\GridBuilder` has no ACL hook,
     * same TODO already called out for the other Grid-backed endpoints in
     * this codebase (see EntityGridBuilder docblocks).
     */
    public function logAction(Request $request): array
    {
        $params = QueryParams::fromRequest($request);
        $params->grouping = ['conversion_id'];

        $builder = new GridBuilder('conversions', self::logColumns(), $this->currentUserService->get());

        return $builder->build($params);
    }

    /**
     * Legacy `conversions.logDefinition` -> `new ConversionsLogDefinition()
     * ->getGridDefinition()`. `range_intervals: null` mirrors
     * `ClickLogDefinition::$_rangeIntervals = NULL` (ConversionsLogDefinition
     * extends ClickLogDefinition, does not re-override it back to the
     * GridDefinition base's `[]` default). `details: {"id":
     * "conversion_id"}` mirrors `ConversionsLogDefinition::$_details`.
     *
     * Column set: every column in self::logColumns() that maps to a real
     * `conversions` table field — see that constant's docblock for what's
     * deliberately excluded and why.
     */
    public function logDefinitionAction(Request $request): array
    {
        $extraParamColumns = [];
        for ($i = 1; $i <= 10; $i++) {
            $extraParamColumns[] = ['name' => "extra_param_{$i}", 'type' => 'string', 'category' => 'params', 'filter' => ['type' => 'string']];
        }

        return [
            'url' => '?object=conversions.log',
            'details' => ['id' => 'conversion_id'],
            'range_intervals' => null,
            'columns' => [
                ['name' => 'profitability', 'type' => 'integer', 'th_title' => '#', 'sortable' => true, 'groupable' => false, 'category' => 'money', 'metric' => true, 'formatter' => 'profitability', 'filter' => ['type' => 'boolean'], 'resizable' => false],
                ['name' => 'conversion_id', 'type' => 'integer', 'title' => 'conversions.conversion_id', 'sortable' => true, 'groupable' => true, 'category' => 'data'],
                ['name' => 'click_datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'clickable' => true, 'category' => 'data', 'width' => 160],
                ['name' => 'postback_datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'clickable' => true, 'category' => 'data', 'width' => 160],
                ['name' => 'sale_datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'category' => 'data'],
                ['name' => 'tid', 'type' => 'string', 'title' => 'conversions.tid', 'filter' => ['type' => 'string'], 'sortable' => true, 'category' => 'data'],
                ['name' => 'campaign_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.campaign', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=campaigns.listAsOptions', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'offer_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.offer', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=offers.index&withGroupName=true', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'landing_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.landing', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=landings.index&withGroupName=true', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'stream_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.stream', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=streams.listAsOptions', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'ts_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.ts', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=trafficSources.index']], 'category' => 'ids', 'width' => 80],
                ['name' => 'affiliate_network_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.affiliate_network', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=affiliateNetworks.index', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'sub_id', 'type' => 'string', 'sortable' => true, 'filter' => ['type' => 'string'], 'category' => 'ids', 'width' => 145],
                ['name' => 'click_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'visitor_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'source_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'referrer_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'search_engine_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'keyword_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'status', 'type' => 'string', 'filter' => ['type' => 'enum', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=conversions.statuses', 'group' => true]], 'sortable' => true, 'category' => 'data'],
                ['name' => 'previous_status', 'type' => 'string', 'title' => 'conversions.previous_status', 'filter' => ['type' => 'enum', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=conversions.statuses', 'group' => true]], 'sortable' => true, 'category' => 'data'],
                ['name' => 'original_status', 'type' => 'string', 'title' => 'conversions.original_status', 'th_title' => 'conversions.original_status_th', 'filter' => ['type' => 'string'], 'sortable' => true, 'category' => 'data'],
                ['name' => 'is_processed', 'type' => 'boolean', 'filter' => ['type' => 'boolean'], 'sortable' => true, 'category' => 'data'],
                ['name' => 'revenue', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'filter' => ['type' => 'decimal'], 'formatter' => 'money_h', 'fraction_size' => 2],
                ['name' => 'cost', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'filter' => ['type' => 'decimal'], 'formatter' => 'money_h', 'fraction_size' => 2],
                ['name' => 'profit', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'summary' => true, 'category' => 'money', 'filter' => ['type' => 'decimal'], 'formatter' => 'money_h', 'fraction_size' => 2],
                ['name' => 'params', 'type' => 'string', 'category' => 'data', 'formatter' => 'object'],
                ...$extraParamColumns,
            ],
        ];
    }

    /**
     * Legacy `conversions.statuses` -> `ConversionRepository::getStatuses()`
     * (`[{id: LEAD|SALE|REJECTED|REBILL, name: <i18n>}]`). No
     * `LocaleService`/i18n layer exists in this Laravel port yet — `name`
     * falls back to a plain `ucfirst($status)` (same "static dictionary,
     * no translation" pattern as `DicsController::currenciesAction()` in
     * this codebase).
     */
    public function statusesAction(Request $request): array
    {
        return array_map(
            fn (string $status) => ['id' => $status, 'name' => ucfirst($status)],
            self::STATUSES,
        );
    }

    /**
     * Legacy `conversions.import` -> `ConversionsService::import($data,
     * $currency)` -> `{"errors": [...], "success": <int>, "total": <int>}`.
     * See `App\Services\ConversionImportService` for the real port (CSV-
     * style `sub_id,revenue[,tid][,status]` rows, one per line, run
     * through the same find-or-update-by-sub_id semantics as a live
     * postback) and its docblock for the one deliberate scope-down
     * (currency conversion — no exchange-rate infra exists anywhere in
     * this project, `$currency` is required but has no effect on the
     * stored revenue).
     */
    public function importAction(Request $request): Response
    {
        $data = $request->input('data');
        $currency = $request->input('currency');

        if (empty($data) || empty($currency)) {
            $message = 'Import data or currency is empty';

            return ResponseFacade::json(['error' => $message, 'stacktrace' => (new \Exception($message))->getTraceAsString()], 406);
        }

        return ResponseFacade::json((new ConversionImportService())->import($data));
    }

    /**
     * Legacy `conversions.updateCostDefinition` -> `new
     * \Component\Clicks\Grid\ClicksDefinition()->getGridDefinition()` — the
     * grid definition used by the admin UI's "bulk-update click cost by
     * filter" form (cf. `campaigns.updateCosts`).
     *
     * TODO (documented stub, per task brief): depends on
     * `Component\Clicks\Grid\ClicksDefinition`, which belongs to the Clicks
     * module — not yet built out as a full CRUD/grid entity in this Laravel
     * port (only `App\Models\Click` + the `clicks` table + the ad-hoc
     * `App\Services\Grid\GridBuilder`/`EntityGridBuilder` column whitelists
     * used by `reports.build`/`campaigns.withStats` exist so far, none of
     * which is "ClicksDefinition" itself). Returns 501 rather than a
     * fabricated definition.
     */
    public function updateCostDefinitionAction(Request $request): Response
    {
        return ResponseFacade::json([
            'error' => 'conversions.updateCostDefinition is not implemented yet (depends on Component\Clicks\Grid\ClicksDefinition, the Clicks module has not been ported as a grid entity — see App\Http\Controllers\Admin\ConversionsController::updateCostDefinitionAction docblock).',
        ], 501);
    }
}
