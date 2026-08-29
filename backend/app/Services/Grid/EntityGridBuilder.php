<?php

namespace App\Services\Grid;

use App\Models\Campaign;
use App\Models\Landing;
use App\Models\Offer;
use App\Models\TrafficSource;
use App\Models\User;
use App\Services\AclService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Port of legacy `Component\EntityGrid\EntityGridFactory`
 * (application/Component/EntityGrid/EntityGridFactory.php) — builds the
 * `<entity>.withStats` response: the entity list (e.g. campaigns) merged
 * with click-level stats aggregated by `<entity>_id` (e.g. `campaign_id`).
 *
 * Contract: docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §9.3. Metric SQL verified against the REAL legacy source, not the doc
 * summary (which turned out to be imprecise on two points — see the two
 * "NOTE (deviation from legacy)" blocks below):
 * - application/Component/Reports/Grid/ReportDefinition.php (metric
 *   inner_select expressions — clicks/conversions/leads/sales/rejected/
 *   revenue/cost/profit all come from ReportDefinition::initColumns(),
 *   which is what CampaignGridDefinition ultimately inherits from);
 * - application/Component/Clicks/Grid/ClicksDefinition.php (base
 *   `revenue`/`cost`/`profit` columns — overridden by ReportDefinition with
 *   the SUM(...)-per-column form, semantically identical);
 * - application/Component/EntityGrid/EntityGridFactory.php (merge logic:
 *   `_getEntities()`, `stats()`, `_merge()`, `_groupStats()`).
 *
 * IMPORTANT — all base metrics below are computed from the `clicks` table
 * ALONE (`is_lead`/`is_sale`/`is_rejected`/`lead_revenue`/`sale_revenue`/
 * `cost` are columns denormalized directly onto each click row by the click
 * pipeline's postback handling). The `conversions` table is a SEPARATE log
 * entity (used by `conversions.log`, a different grid) and is NOT joined
 * here — confirmed by reading ReportDefinition::initColumns(), which never
 * references the conversions table for any of these columns.
 */
class EntityGridBuilder
{
    /**
     * Metric name => raw SQL aggregate expression. Ported 1:1 from
     * `ReportDefinition::initColumns()` `inner_select` values (see class
     * docblock). Only the base set requested for this round is included —
     * extending this map is how a future round adds more `withStats`
     * metrics (uc_*_rate, roi, epc, ... all need `outer_select`/
     * `required_columns` support this class does not implement yet).
     */
    private const METRIC_EXPRESSIONS = [
        // "COUNT(click_id)" — ReportDefinition.php:29
        'clicks' => 'COUNT(click_id)',
        // NOTE (deviation from doc, not from source): the task brief and
        // §9.3 describe "conversions" as a count of is_sale/is_lead rows.
        // The real legacy SQL (ReportDefinition.php:40) is
        // "(SUM(is_sale) + SUM(is_lead) + SUM(is_rejected))" — it ALSO
        // counts rejected clicks. Implemented per the verified real
        // source, not the doc's paraphrase.
        'conversions' => '(SUM(is_sale) + SUM(is_lead) + SUM(is_rejected))',
        'leads' => 'SUM(is_lead)',
        'sales' => 'SUM(is_sale)',
        'rejected' => 'SUM(is_rejected)',
        // "SUM(lead_revenue) + SUM(sale_revenue)" — ReportDefinition.php:46
        // (does NOT include rejected_revenue).
        'revenue' => 'SUM(lead_revenue) + SUM(sale_revenue)',
        'lead_revenue' => 'SUM(lead_revenue)',
        'sale_revenue' => 'SUM(sale_revenue)',
        'rejected_revenue' => 'SUM(rejected_revenue)',
        // "SUM(cost)" — ReportDefinition.php:50
        'cost' => 'SUM(cost)',
        // "SUM(lead_revenue) + SUM(sale_revenue) - SUM(cost)" — ReportDefinition.php:51
        'profit' => 'SUM(lead_revenue) + SUM(sale_revenue) - SUM(cost)',
    ];

    /** Integer-valued metrics (cast (int) instead of (float) on output). */
    private const INTEGER_METRICS = ['clicks', 'conversions', 'leads', 'sales', 'rejected'];

    /**
     * Base metric set for `withStats` when the caller doesn't request a
     * specific `metrics` list. NOTE (deviation from legacy): the real
     * `EntityGridFactory::_filteredMetrics()` returns NULL when `metrics`
     * is absent from the request, and `stats()` then skips the stats query
     * entirely (`empty($params["metrics"])` branch) — i.e. legacy computes
     * ZERO metrics unless the frontend explicitly lists them. Defaulting to
     * this base set instead is a deliberate improvement matching the task
     * brief ("базовыми метриками: clicks/conversions/revenue/cost/profit"),
     * not a fidelity requirement — a `withStats` call that names no metrics
     * would otherwise return campaigns with no stats at all, which is not
     * useful for this Laravel rewrite's first user (the gridDefinition
     * default columns for Campaigns, see CampaignsController).
     */
    public const DEFAULT_METRICS = ['clicks', 'conversions', 'revenue', 'cost', 'profit'];

    /** Legacy `EntityGridFactory::WITH_CLICKS` — special `state` filter value. */
    private const WITH_CLICKS = 'with_clicks';

    /**
     * @param  class-string<Model>  $entityClass  e.g. Campaign::class
     * @param  string  $statsIdColumn  FK column on `clicks` that identifies the entity, e.g. "campaign_id"
     * @param  string[]  $entityFields  entity columns to include in each output row (must include the primary key)
     */
    public function __construct(
        private readonly string $entityClass,
        private readonly string $statsIdColumn,
        private readonly array $entityFields = ['id', 'name', 'state'],
        private readonly ?User $user = null,
    ) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, meta: array{total: int}}
     */
    public function build(QueryParams $params): array
    {
        $model = new $this->entityClass;
        $entityTable = $model->getTable();
        $primaryKey = $model->getKeyName();

        [$entityFilters, $statsFilters, $shouldMerge] = $this->splitFilters($params->filters, $entityTable);

        $entities = $this->loadEntities($model, $entityFilters, $primaryKey);

        $ids = array_column($entities, $primaryKey);
        $metrics = $this->resolveMetrics($params->metrics);

        $statsById = (empty($ids) || empty($metrics))
            ? []
            : $this->loadStats($ids, $metrics, $statsFilters, $params->range);

        $rows = $this->merge($entities, $primaryKey, $statsById, $metrics, $shouldMerge);

        $rows = $this->applySort($rows, $params->sort);

        $total = count($rows);

        if ($params->limit > 0) {
            $rows = array_slice($rows, $params->offset, $params->limit);
        } elseif ($params->offset > 0) {
            $rows = array_slice($rows, $params->offset);
        }

        return [
            'rows' => array_values($rows),
            // NOTE (deviation from legacy source, matches task brief + doc
            // §9.3 instead): the real `EntityGridFactory::build()` returns
            // `$stats["meta"]` verbatim from `ReportRepository::get()`,
            // which — once metrics are non-empty — is JsonRenderer's
            // `{execution_time, datetime}` (see JsonRenderer::create()),
            // NOT `{total: N}`. Only the "no metrics requested" short-circuit
            // branch in the real EntityGridFactory ever produces
            // `{total: count($entities)}`. That looks like a real
            // inconsistency in the legacy code (pagination metadata is lost
            // on the common path) rather than an intentional design — the
            // task brief and doc §9.3 both describe `{rows, meta:{total}}`
            // as the intended contract, so that's what's implemented here,
            // consistently, on every path.
            'meta' => ['total' => $total],
        ];
    }

    /**
     * Legacy `_getEntities()` filter split: a filter whose `name` matches a
     * real column on the entity's own table is applied directly to the
     * entity query and consumed (not forwarded); everything else is
     * forwarded to the stats (clicks) query. The special
     * `{name: "state", expression: "with_clicks"}` filter is consumed
     * without being applied anywhere — it only flips $shouldMerge to false.
     *
     * NOTE (deviation from legacy): the real `_getEntities()` applies every
     * matched entity filter as a forced `=` comparison regardless of the
     * filter's own `operator` (`$filter["name"] . " = " . Db::quote(...)`)
     * — i.e. IN_LIST/CONTAINS/etc. on an entity field silently become
     * equality in the legacy code. This port instead applies the filter's
     * real operator via FilterOperator::apply() (already built, reused
     * as-is per the task brief), which is strictly more correct and no
     * less safe.
     *
     * @param  array<int, array{name: string, operator: string, expression: mixed, case_sensitive?: bool}>  $filters
     * @return array{0: array, 1: array, 2: bool} [entityFilters, statsFilters, shouldMerge]
     */
    private function splitFilters(array $filters, string $entityTable): array
    {
        $entityColumns = Schema::getColumnListing($entityTable);

        $entityFilters = [];
        $statsFilters = [];
        $shouldMerge = true;

        foreach ($filters as $filter) {
            $name = $filter['name'] ?? null;
            if ($name === null) {
                continue;
            }

            if ($name === 'state' && ($filter['expression'] ?? null) === self::WITH_CLICKS) {
                $shouldMerge = false;

                continue;
            }

            if (in_array($name, $entityColumns, true)) {
                $entityFilters[] = $filter;
            } else {
                $statsFilters[] = $filter;
            }
        }

        return [$entityFilters, $statsFilters, $shouldMerge];
    }

    /**
     * Legacy `_getEntities()`: loads entities matching the (non-metric)
     * filters, always excluding `state = deleted` (legacy:
     * `t.state <> Core\Entity\State::DELETED`, unconditional — kept even if
     * the caller passed an explicit `state` filter, same as legacy).
     *
     * ACL is applied here (see applyAcl()) — legacy: `AclService::
     * filterByAcl($items, false, $user)` for entity types that carry their
     * own ACL entity_type (Offers/Landings/TrafficSources), or the
     * `campaign_id IN (...)` restriction legacy's `AccessRestriction`
     * builds from `AclService::getAllowedCampaignIds()` for Campaigns
     * themselves and for Streams (which have no ACL entity_type of their
     * own — access always flows through the parent campaign, see
     * App\Services\AclService class docblock).
     *
     * NOTE (deviation from legacy): the real `_getEntities()`/`repository()
     * ->all()` loads ALL matching entities unbounded — `limit`/`offset`
     * from the request are only ever applied inside the stats() clicks
     * query (which groups by entity id), meaning pagination on the real
     * `withStats` endpoint doesn't paginate the entity list at all, it
     * silently truncates which entities' stats get computed (entities
     * whose id falls outside the truncated GROUP BY result get zero-filled
     * even if they have real stats). That's not a sensible pagination
     * contract to carry forward, so this port applies limit/offset to the
     * final merged+sorted row list instead (see build()) and loads every
     * matching entity here.
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadEntities($model, array $entityFilters, string $primaryKey): array
    {
        /** @var Builder $query */
        $query = $model->newQuery()->select($this->entityFields);

        foreach ($entityFilters as $filter) {
            FilterOperator::apply(
                $query,
                $filter['name'],
                $filter['operator'] ?? 'EQUALS',
                $filter['expression'] ?? null,
                (bool) ($filter['case_sensitive'] ?? true),
            );
        }

        $query->where('state', '!=', 'deleted');

        $models = $query->orderBy($primaryKey)->get();
        $models = $this->applyAcl($models, $primaryKey);

        return $models
            ->map(fn ($entity) => $entity->only($this->entityFields))
            ->all();
    }

    /**
     * ACL enforcement, ported from legacy `Component\Clicks\Grid\
     * AccessRestriction` (campaign_id-based filter, auto-added by
     * `GridBuilder::factory()` for clicks/conversions-backed grids) and
     * `Component\Users\Service\AclService::filterByAcl()` (entity-level
     * filter, used directly by the real `_getEntities()` in
     * `EntityGridFactory`).
     *
     * Offers/Landings/TrafficSources each have their own `acl_rules.
     * entity_type` (see App\Services\AclService::ACL_KEYS), so they're
     * filtered the same way any other ACL-gated entity list is filtered
     * elsewhere in this codebase: `AclService::filterByAcl()` on the
     * loaded model list.
     *
     * Campaigns and Streams are filtered by allowed campaign id instead:
     * Campaigns because that IS the entity's own id, Streams because a
     * Stream has no ACL entity_type of its own — legacy always resolves a
     * stream's access through its parent campaign (App\Services\AclService
     * class docblock, "$_aclKey=NULL" note) — and a Stream row always
     * carries its own `campaign_id` FK to filter by (unlike Offers/
     * Landings/TrafficSources, which have no direct campaign_id column at
     * all).
     */
    private function applyAcl(EloquentCollection $models, string $primaryKey): Collection
    {
        $aclService = app(AclService::class);

        if (in_array($this->entityClass, [Offer::class, Landing::class, TrafficSource::class], true)) {
            return collect($aclService->filterByAcl($models, false, $this->user))->values();
        }

        $allowedCampaignIds = $aclService->getAllowedCampaignIds($this->user);

        if ($allowedCampaignIds === AclService::ALLOW_ANY) {
            return $models;
        }

        if ($allowedCampaignIds === AclService::ALLOW_NONE) {
            return collect();
        }

        $campaignIdField = $this->entityClass === Campaign::class ? $primaryKey : 'campaign_id';

        return $models
            ->filter(fn ($entity) => in_array((int) $entity->{$campaignIdField}, $allowedCampaignIds, true))
            ->values();
    }

    /**
     * Legacy `_filteredMetrics()`: the requested metric list minus the
     * "more" pseudo-metric (a UI-only marker in legacy, meaningless here),
     * minus any metric this class doesn't know how to compute. Falls back
     * to DEFAULT_METRICS when the caller didn't specify any (see
     * DEFAULT_METRICS docblock for why that differs from legacy).
     *
     * @return string[]
     */
    private function resolveMetrics(array $requested): array
    {
        $requested = array_values(array_diff($requested, ['more']));

        if (empty($requested)) {
            $requested = self::DEFAULT_METRICS;
        }

        return array_values(array_intersect($requested, array_keys(self::METRIC_EXPRESSIONS)));
    }

    /**
     * Legacy `stats()`: aggregates `clicks` grouped by `$statsIdColumn`
     * (e.g. `campaign_id`), restricted to the given entity ids, with any
     * forwarded (non-entity-field) filters and the request's time range
     * applied.
     *
     * @param  int[]  $ids
     * @param  string[]  $metrics
     * @return array<int|string, array<string, mixed>> keyed by entity id
     */
    private function loadStats(array $ids, array $metrics, array $statsFilters, ?array $range): array
    {
        $selectExpressions = [$this->statsIdColumn];
        foreach ($metrics as $metric) {
            $selectExpressions[] = self::METRIC_EXPRESSIONS[$metric].' as '.$metric;
        }

        $query = DB::table('clicks')
            ->select(array_map(fn ($e) => DB::raw($e), $selectExpressions))
            ->whereIn($this->statsIdColumn, $ids)
            ->groupBy($this->statsIdColumn);

        foreach ($statsFilters as $filter) {
            $name = $filter['name'] ?? null;
            if ($name === null) {
                continue;
            }

            FilterOperator::apply(
                $query,
                $name,
                $filter['operator'] ?? 'EQUALS',
                $filter['expression'] ?? null,
                (bool) ($filter['case_sensitive'] ?? true),
            );
        }

        $this->applyRange($query, $range);

        $rows = [];
        foreach ($query->get() as $row) {
            $row = (array) $row;
            // Cast to int: PDO driver return types for a GROUP BY column
            // aren't guaranteed consistent (string vs int) across MySQL vs
            // sqlite (used in tests) — normalize so the lookup in merge()
            // (keyed by the entity's own, Eloquent-cast-to-int primary key)
            // always matches.
            $id = (int) $row[$this->statsIdColumn];

            foreach ($metrics as $metric) {
                $row[$metric] = in_array($metric, self::INTEGER_METRICS, true)
                    ? (int) $row[$metric]
                    : (float) $row[$metric];
            }

            $rows[$id] = $row;
        }

        return $rows;
    }

    /**
     * Ported from `Component\Grid\Definition\TimeRange` (from/to + a subset
     * of the named `interval` values — `all_time` disables filtering
     * entirely, matching legacy `$_filtering = false`). Filters on the
     * entity grid definition's range field, which for Campaigns (via
     * ReportDefinition -> ClicksDefinition) is always `clicks.datetime`.
     */
    private function applyRange($query, ?array $range): void
    {
        if (empty($range)) {
            return;
        }

        $timezone = new \DateTimeZone($range['timezone'] ?? 'UTC');
        $utc = new \DateTimeZone('UTC');
        $start = null;
        $end = null;

        if (! empty($range['from']) || ! empty($range['to'])) {
            if (! empty($range['from'])) {
                $start = new \DateTime($range['from'], $timezone);
            }
            if (! empty($range['to'])) {
                $to = $range['to'];
                if (! str_contains($to, ' ') && ! str_contains($to, 'T')) {
                    $to .= ' 23:59:59';
                }
                $end = new \DateTime($to, $timezone);
            }
        } elseif (! empty($range['interval'])) {
            $interval = $range['interval'];
            if ($interval === 'all_time') {
                return;
            }

            switch ($interval) {
                case 'last_monday':
                    $start = new \DateTime('monday this week', $timezone);
                    $end = new \DateTime('now', $timezone);
                    break;
                case 'previous_month':
                    $start = new \DateTime('first day of last month', $timezone);
                    $end = new \DateTime('last day of last month', $timezone);
                    break;
                case 'first_day_of_this_year':
                    $start = new \DateTime(date('Y').'-01-01', $timezone);
                    $end = new \DateTime('now', $timezone);
                    break;
                case 'yesterday':
                    $start = new \DateTime('yesterday', $timezone);
                    $end = new \DateTime('-1 day', $timezone);
                    break;
                default:
                    $relative = str_replace('_', ' ', $interval);
                    $start = new \DateTime($relative, $timezone);
                    $end = new \DateTime('now', $timezone);
                    $start->setTime(0, 0, 0);
                    $end->setTime(23, 59, 59);
            }
        } else {
            return;
        }

        if ($start && $end) {
            $query->whereBetween('datetime', [
                $start->setTimezone($utc)->format('Y-m-d H:i:s'),
                $end->setTimezone($utc)->format('Y-m-d H:i:s'),
            ]);
        } elseif ($start) {
            $query->where('datetime', '>=', $start->setTimezone($utc)->format('Y-m-d H:i:s'));
        } elseif ($end) {
            $query->where('datetime', '<=', $end->setTimezone($utc)->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Legacy `_merge()` + `_groupStats()`: entity fields win over stats
     * fields on key collision (`array_merge($stats, $entity)`); entities
     * with no matching stats row get all-zero metrics UNLESS `$shouldMerge`
     * is false (the `state=with_clicks` filter), in which case they're
     * dropped from the result entirely — matches legacy exactly.
     *
     * @param  array<int, array<string, mixed>>  $entities
     * @param  array<int|string, array<string, mixed>>  $statsById
     * @param  string[]  $metrics
     * @return array<int, array<string, mixed>>
     */
    private function merge(array $entities, string $primaryKey, array $statsById, array $metrics, bool $shouldMerge): array
    {
        $zeroMetrics = array_fill_keys($metrics, 0);
        $rows = [];

        foreach ($entities as $entity) {
            $id = (int) $entity[$primaryKey];

            if (isset($statsById[$id])) {
                $rows[] = array_merge($statsById[$id], $entity);
            } elseif ($shouldMerge) {
                $rows[] = array_merge($zeroMetrics, $entity);
            }
            // else: no stats and $shouldMerge === false -> entity dropped,
            // matches legacy _merge() leaving that $newRows[$i] unset.
        }

        return $rows;
    }

    /**
     * Basic sort support over the final merged row list — covers both
     * entity fields and metric columns uniformly (the real GridBuilder only
     * sorts within the SQL query and can't sort by a value that doesn't
     * exist until after the PHP-side merge, e.g. a metric on a
     * zero-filled row). Not present in the real EntityGridFactory at all
     * (sort isn't read there) — a reasonable extension within the task's
     * "code should be correct, not just replicate today's no-op" brief,
     * since QueryParams already parses `sort` and dropping it silently
     * would be a worse contract for the future than this.
     */
    private function applySort(array $rows, array $sort): array
    {
        if (empty($sort)) {
            return $rows;
        }

        // Only the first sort clause is applied (multi-column sort is out
        // of scope for this round).
        $clause = $sort[0];
        $column = $clause['name'] ?? null;
        if ($column === null) {
            return $rows;
        }
        $descending = strtoupper($clause['order'] ?? 'ASC') === 'DESC';

        usort($rows, function ($a, $b) use ($column, $descending) {
            $av = $a[$column] ?? null;
            $bv = $b[$column] ?? null;
            $cmp = $av <=> $bv;

            return $descending ? -$cmp : $cmp;
        });

        return $rows;
    }
}
