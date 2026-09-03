<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AclService;
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
 * All 6 legacy actions are implemented: `log`, `logDefinition`,
 * `statuses`, `import` (see `App\Services\ConversionImportService`), and
 * `updateCostDefinition` (see its own docblock — a prior version left
 * this as a 501 stub on a since-corrected premise).
 *
 * REAL BUG, found live against legacy port 8090 (2026-09-03): none of
 * these 5 actions had an ACL gate. Real legacy gates the WHOLE controller
 * uniformly, BEFORE any action runs
 * (`Admin\AdminRequest\AdminRequestFactory::checkAuthorization()` ->
 * `AclService::isResourceAllowed($user, "conversions")`, throwing a
 * `DenyError` with the message "You have no permission to access to this
 * page - Conversions" - `mb_ucfirst($adminRequest->getController())`
 * appended to a shared translation string, confirmed by reading
 * application/Admin/AdminRequest/AdminRequestFactory.php:50-51 directly).
 * "conversions" is NOT a resource any non-admin USER has by default
 * (verified live: a freshly created USER's `resources` array never
 * includes it) so this 403s for every ordinary user, not just an
 * intentionally-restricted one. `self::forbidden()` below + a guard at
 * the top of every action replicates this.
 */
class ConversionsController extends Controller
{
    public function __construct(
        private readonly CurrentUserService $currentUserService,
        private readonly AclService $aclService,
    ) {}

    private function forbidden(): Response
    {
        return ResponseFacade::json([
            'error' => 'You have no permission to access to this page - Conversions',
        ], 403);
    }

    private function denyUnlessAllowed(): ?Response
    {
        if (! $this->aclService->isResourceAllowed($this->currentUserService->get(), 'conversions')) {
            return $this->forbidden();
        }

        return null;
    }

    /**
     * Legacy conversion status constants (`Traffic\Model\Conversion::LEAD` /
     * `SALE` / `REJECTED` / `REBILL`, confirmed by reading
     * application/Traffic/Model/Conversion.php:14-17 directly — NOT a
     * guess). Order matches `ConversionRepository::getStatuses()`.
     */
    private const STATUSES = ['lead', 'sale', 'rejected', 'rebill'];

    /**
     * Display names, cross-checked against legacy's real translation
     * strings (application/Component/Conversions/translations/en.php,
     * `conversions.statuses`) rather than assumed - `rebill`'s real
     * legacy label is "Upsell", not a `ucfirst()`-derived "Rebill" (a
     * naive fallback can never produce that; found live, 2026-09-03).
     */
    private const STATUS_NAMES = [
        'lead' => 'Lead',
        'sale' => 'Sale',
        'rejected' => 'Rejected',
        'rebill' => 'Upsell',
    ];

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
    public function logAction(Request $request): array|Response
    {
        if ($deny = $this->denyUnlessAllowed()) {
            return $deny;
        }

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
    public function logDefinitionAction(Request $request): array|Response
    {
        if ($deny = $this->denyUnlessAllowed()) {
            return $deny;
        }

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
     * is a hardcoded copy of legacy's real English translation string
     * (self::STATUS_NAMES) rather than a derived `ucfirst($status)`,
     * since that can't produce `rebill => "Upsell"`.
     */
    public function statusesAction(Request $request): array|Response
    {
        if ($deny = $this->denyUnlessAllowed()) {
            return $deny;
        }

        return array_map(
            fn (string $status) => ['id' => $status, 'name' => self::STATUS_NAMES[$status]],
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
        if ($deny = $this->denyUnlessAllowed()) {
            return $deny;
        }

        $data = $request->input('data');
        $currency = $request->input('currency');

        // CORRECTION (2026-09-03): a prior version of this returned a JSON
        // 406 here, unverified. Live-checked against legacy port 8090 for
        // BOTH missing `data` and missing `currency`: real legacy throws a
        // generic `\Core\Application\Exception\Error("Import data or
        // currency is empty")` (application/Component/Conversions/
        // Controller/ConversionsController.php:33) — same class as
        // "Must be post request" elsewhere in this codebase, which falls
        // to the catch-all handler: HTTP 500, plain text, not JSON.
        if (empty($data) || empty($currency)) {
            return response('Import data or currency is empty', 500);
        }

        return ResponseFacade::json((new ConversionImportService())->import($data));
    }

    /**
     * Legacy `conversions.updateCostDefinition` -> `new
     * \Component\Clicks\Grid\ClicksDefinition()->getGridDefinition()`.
     *
     * CORRECTION (2026-09-03): a prior version of this action returned a
     * hard 501, on the premise that `ClicksDefinition` — "the Clicks
     * module's grid entity" — didn't exist in this port and building one
     * from scratch was a separate, large task. Re-reading the real legacy
     * `ClicksDefinition::initColumns()`/`getGridDefinition()` directly
     * shows that's an overstatement: unlike `reports.build`/
     * `conversions.log` (which run a REAL query built from a
     * `GridDefinition`), `updateCostDefinitionAction()` does nothing but
     * construct the object and call `getGridDefinition()` — it never
     * queries anything. It is PURE METADATA (an inert `{url, details,
     * range_intervals, columns}` describing the "bulk-update click cost by
     * filter" form's available columns/filters), confirmed live against
     * legacy port 8090 — verified byte-for-byte that legacy's real output
     * carries `url: null`, `details: null`, `range_intervals: []`.
     * Building that requires no live grid/query machinery at all, just a
     * column list — same style as `logDefinitionAction()` above and
     * `ReportsController::definitionAction()` (name/type/category/filter/
     * groupable/sortable/hidden/metric/summary only, no `inner_select`/
     * `title`/`resizable`/decorators — this port's established "no i18n,
     * no internal-SQL-leak" simplification, consistent everywhere else a
     * `*DefinitionAction` exists in this codebase).
     *
     * Column set: the real `ClicksDefinition::initColumns()` column list,
     * MINUS the `<x>_id -> <x>` dereferenced-name text columns this port
     * has no join wired for anywhere (campaign/offer/landing/ts/stream/
     * affiliate_network/campaign_group/parent_campaign/language/
     * device_type/connection_type/country/region/city/operator/os/
     * browser/device_model/isp/user_agent) — same, already-established
     * precedent as `ReportsController::BUILD_COLUMNS_BASE`'s own docblock
     * (keep the raw `*_id` FK column with its `enum` filter/dictionary,
     * drop the name column nothing here can actually resolve). Icon-only
     * cosmetic columns (`os_icon`/`browser_icon`/`country_flag`,
     * `exclude_from_details: true` in legacy, UI-decoration only) are
     * dropped for the same reason. `source`/`referrer`/`search_engine`/
     * `keyword`/`destination`/`ad_campaign_id`/`external_id`/
     * `creative_id`/`x_requested_with` are legacy TEXT columns dereferenced
     * through a `ref_*` dictionary relation — this port's `clicks` table
     * only has the raw `*_id` FK (see `2025_01_01_000018_create_clicks_
     * table.php`), so these are listed under their real `*_id` column
     * names instead (matches `ReportsController::BUILD_COLUMNS_BASE`
     * again, which made the identical call for the same columns).
     */
    public function updateCostDefinitionAction(Request $request): array|Response
    {
        if ($deny = $this->denyUnlessAllowed()) {
            return $deny;
        }

        $subIdColumns = [];
        for ($i = 1; $i <= 15; $i++) {
            $subIdColumns[] = ['name' => "sub_id_{$i}", 'type' => 'string', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'string'], 'category' => 'sub_ids', 'width' => 100];
        }

        $extraParamColumns = [];
        for ($i = 1; $i <= 10; $i++) {
            $extraParamColumns[] = ['name' => "extra_param_{$i}", 'type' => 'string', 'filter' => ['type' => 'string'], 'category' => 'params'];
        }

        return [
            'url' => null,
            'details' => null,
            'range_intervals' => [],
            'columns' => [
                ['name' => 'profitability', 'type' => 'decimal', 'th_title' => '#', 'sortable' => true, 'groupable' => false, 'category' => 'money', 'metric' => true, 'formatter' => 'profitability', 'filter' => ['type' => 'boolean'], 'width' => 3],
                ['name' => 'click_id', 'type' => 'integer', 'sortable' => true, 'primary' => true, 'category' => 'ids', 'groupable' => true, 'hidden' => true, 'width' => 80],
                ['name' => 'datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'category' => 'data', 'clickable' => true, 'width' => 160],
                ['name' => 'sub_id', 'type' => 'string', 'sortable' => true, 'filter' => ['type' => 'string'], 'category' => 'ids', 'width' => 145],
                ['name' => 'visitor_id', 'type' => 'string', 'sortable' => true, 'filter' => ['type' => 'string'], 'groupable' => true, 'category' => 'ids'],
                ['name' => 'campaign_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.campaign', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=campaigns.listAsOptions', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'campaign_group_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.campaign_group', 'category' => 'data', 'dictionary' => ['url' => '?object=groups.listAsOptions&type=campaigns']], 'category' => 'ids', 'width' => 80],
                ['name' => 'parent_campaign_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.parent_campaign', 'category' => 'data', 'dictionary' => ['url' => '?object=campaigns.listAsOptions', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'landing_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.landing', 'category' => 'data', 'dictionary' => ['url' => '?object=landings.index&withGroupName=true', 'valueProp' => 'id', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'landing_clicked_datetime', 'type' => 'datetime', 'sortable' => true, 'formatter' => 'datetime', 'category' => 'data'],
                ['name' => 'landing_clicked_period', 'type' => 'string', 'metric' => true, 'sortable' => true, 'filter' => ['type' => 'integer'], 'formatter' => 'time_diff', 'category' => 'data'],
                ['name' => 'offer_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.offer', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=offers.index&withGroupName=true', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'affiliate_network_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.affiliate_network', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=affiliateNetworks.index', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'ts_id', 'type' => 'integer', 'sortable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.ts', 'category' => 'data', 'dictionary' => ['valueProp' => 'id', 'url' => '?object=trafficSources.index']], 'category' => 'ids', 'width' => 80],
                ['name' => 'stream_id', 'type' => 'integer', 'sortable' => true, 'groupable' => true, 'filter' => ['type' => 'enum', 'title' => 'grid.stream', 'category' => 'data', 'dictionary' => ['url' => '?object=streams.listAsOptions', 'valueProp' => 'id', 'group' => true]], 'category' => 'ids', 'width' => 80],
                ['name' => 'is_unique_stream', 'type' => 'boolean', 'th_title' => 'grid.is_unique_stream_th', 'sortable' => true, 'category' => 'data', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_unique_campaign', 'type' => 'boolean', 'th_title' => 'grid.is_unique_campaign_th', 'filter' => ['type' => 'boolean'], 'groupable' => true, 'sortable' => true, 'category' => 'data', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_unique_global', 'type' => 'boolean', 'th_title' => 'grid.is_unique_global_th', 'filter' => ['type' => 'boolean'], 'groupable' => true, 'sortable' => true, 'category' => 'data', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_lead', 'type' => 'boolean', 'filter' => ['type' => 'boolean'], 'sortable' => true, 'groupable' => true, 'category' => 'money', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_sale', 'type' => 'boolean', 'filter' => ['type' => 'boolean'], 'sortable' => true, 'groupable' => true, 'category' => 'money', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_rejected', 'type' => 'boolean', 'filter' => ['type' => 'boolean'], 'sortable' => true, 'groupable' => true, 'category' => 'money', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_bot', 'type' => 'boolean', 'filter' => ['type' => 'boolean'], 'sortable' => true, 'groupable' => true, 'category' => 'device', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'is_using_proxy', 'type' => 'boolean', 'th_title' => 'grid.is_using_proxy_th', 'filter' => ['type' => 'boolean'], 'sortable' => true, 'groupable' => true, 'category' => 'geo', 'formatter' => 'boolean', 'width' => 80],
                ['name' => 'language_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.language', 'category' => 'device', 'dictionary' => ['url' => '?object=clicks.dictionary&name=languages', 'column' => 'language_id']], 'hidden' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'device_type_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.device_type', 'category' => 'device', 'dictionary' => ['url' => '?object=clicks.dictionary&name=deviceTypes']], 'hidden' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'connection_type_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.connection_type', 'category' => 'connection', 'dictionary' => ['url' => '?object=clicks.dictionary&name=connectionTypes']], 'hidden' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'ip_id', 'type' => 'ip', 'hidden' => true, 'groupable' => true, 'sortable' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'country_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.country', 'category' => 'geo', 'dictionary' => ['url' => '?object=clicks.dictionary&name=countries']], 'hidden' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'region_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.region', 'category' => 'geo', 'dictionary' => ['url' => '?object=clicks.dictionary&name=regions']], 'hidden' => true, 'category' => 'ids'],
                ['name' => 'city_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.city', 'category' => 'geo', 'dictionary' => ['url' => '?object=clicks.dictionary&name=cities']], 'sortable' => true, 'hidden' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'user_agent_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.user_agent'], 'category' => 'ids', 'hidden' => true, 'width' => 80],
                ['name' => 'operator_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.operator', 'category' => 'connection', 'dictionary' => ['url' => '?object=clicks.dictionary&name=operators']], 'hidden' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'os_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.os', 'category' => 'device', 'dictionary' => ['url' => '?object=clicks.dictionary&name=os']], 'category' => 'ids', 'hidden' => true, 'sortable' => true, 'width' => 80],
                ['name' => 'os_version', 'type' => 'version', 'filter' => ['type' => 'version', 'title' => 'grid.os_version'], 'groupable' => true, 'sortable' => true, 'category' => 'device', 'width' => 80],
                ['name' => 'browser_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.browser', 'category' => 'device', 'dictionary' => ['url' => '?object=clicks.dictionary&name=browsers']], 'hidden' => true, 'sortable' => true, 'category' => 'ids', 'width' => 80],
                ['name' => 'browser_version', 'type' => 'version', 'filter' => ['type' => 'version', 'title' => 'grid.browser_version'], 'groupable' => true, 'sortable' => true, 'category' => 'device'],
                ['name' => 'device_model_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.device_model', 'category' => 'device', 'dictionary' => ['url' => '?object=clicks.dictionary&name=deviceModels']], 'hidden' => true, 'category' => 'ids', 'sortable' => true, 'width' => 80],
                ['name' => 'isp_id', 'type' => 'integer', 'filter' => ['type' => 'enum', 'title' => 'grid.isp', 'category' => 'connection', 'dictionary' => ['url' => '?object=clicks.dictionary&name=isp']], 'hidden' => true, 'category' => 'ids', 'sortable' => true, 'width' => 80],
                ['name' => 'source_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'x_requested_with_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'ad_campaign_id_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true, 'width' => 80],
                ['name' => 'external_id_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'creative_id_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'referrer_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'search_engine_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'keyword_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ['name' => 'destination_id', 'type' => 'integer', 'sortable' => true, 'category' => 'ids', 'hidden' => true],
                ...$subIdColumns,
                ...$extraParamColumns,
                ['name' => 'revenue', 'type' => 'decimal', 'metric' => true, 'filter' => ['type' => 'decimal'], 'sortable' => true, 'category' => 'money', 'fraction_size' => 4, 'formatter' => 'money'],
                ['name' => 'lead_revenue', 'type' => 'decimal', 'metric' => true, 'filter' => ['type' => 'decimal'], 'sortable' => true, 'summary' => true, 'category' => 'money', 'fraction_size' => 4, 'formatter' => 'money', 'width' => 80],
                ['name' => 'sale_revenue', 'type' => 'decimal', 'metric' => true, 'filter' => ['type' => 'decimal'], 'sortable' => true, 'summary' => true, 'category' => 'money', 'fraction_size' => 4, 'formatter' => 'money', 'width' => 80],
                ['name' => 'cost', 'type' => 'decimal', 'metric' => true, 'sortable' => true, 'filter' => ['type' => 'decimal'], 'category' => 'money', 'fraction_size' => 4, 'formatter' => 'money', 'width' => 100],
                ['name' => 'profit', 'type' => 'decimal', 'th_title' => 'grid.profit_th', 'metric' => true, 'sortable' => false, 'filter' => ['type' => 'decimal'], 'category' => 'money', 'formatter' => 'money_h', 'fraction_size' => 4],
            ],
        ];
    }
}
