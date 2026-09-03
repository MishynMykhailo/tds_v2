<?php

namespace App\Services\Grid;

use App\Models\User;
use App\Services\AclService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Port of legacy `Component\Grid\Builder\GridBuilder` (application/
 * Component/Grid/Builder/GridBuilder.php) — the "plain report" query
 * builder, used by `ReportsController::buildAction()` (arbitrary
 * dimensions/metrics/filters against `clicks` or `conversions`, NOT tied to
 * one entity's id — that's the separate `EntityGridBuilder`, used by
 * `withStats`). Contract: docs/legacy-reference/frontend/api/
 * 00_common_routing_auth_acl_errors_grid.md §9.3 (first branch — "Для
 * чистых отчётов").
 *
 * Column/metric SQL expressions are intentionally NOT taken from arbitrary
 * caller input — only from a fixed whitelist map passed in by the caller
 * (GridDefinition-equivalent), to avoid SQL injection via column names.
 */
class GridBuilder
{
    /**
     * @param  string  $table  "clicks" or "conversions"
     * @param  array<string, string>  $columnExpressions  logical column name -> raw SQL expression (whitelist, e.g. ["campaign_id" => "campaign_id", "clicks" => "COUNT(click_id)"])
     * @param  array<int, array{0: string, 1: string, 2: string, 3: string}>  $joins  optional LEFT JOINs applied to every query this builder runs (main select, total count, summary), e.g. [["visitors", "clicks.visitor_id", "=", "visitors.id"]] — lets $columnExpressions reference columns on the joined tables. Empty by default (single-table, as before this param existed).
     */
    public function __construct(
        private readonly string $table,
        private readonly array $columnExpressions,
        private readonly ?User $user = null,
        private readonly array $joins = [],
    ) {}

    /**
     * @return array{rows: array<int, array<string, mixed>>, total: int, summary: array<string, mixed>|null, meta: array{execution_time: string, datetime: string}}
     */
    public function build(QueryParams $params): array
    {
        $start = microtime(true);

        // ACL, ported from legacy `Component\Clicks\Grid\AccessRestriction`
        // (auto-added by the real `GridBuilder::factory()` to every
        // clicks/conversions-backed grid — both tables carry a `campaign_id`
        // column here, see App\Services\AclService::getAllowedCampaignIds()).
        // ALLOW_NONE short-circuits without touching the DB at all, per the
        // task brief.
        $allowedCampaignIds = app(AclService::class)->getAllowedCampaignIds($this->user);

        if ($allowedCampaignIds === AclService::ALLOW_NONE) {
            return [
                'rows' => [],
                'total' => 0,
                'summary' => null,
                'meta' => [
                    'execution_time' => number_format(microtime(true) - $start, 4),
                    'datetime' => now()->toIso8601String(),
                ],
            ];
        }

        $selectColumns = ! empty($params->columns) ? $params->columns : array_keys($this->columnExpressions);
        $selectColumns = array_values(array_intersect($selectColumns, array_keys($this->columnExpressions)));

        $query = $this->baseQuery($allowedCampaignIds);

        foreach ($selectColumns as $column) {
            $query->selectRaw($this->columnExpressions[$column].' as '.$column);
        }

        foreach ($params->filters as $filter) {
            if (! isset($filter['name'], $filter['operator']) || ! isset($this->columnExpressions[$filter['name']])) {
                continue;
            }
            FilterOperator::apply(
                $query,
                $this->columnExpressions[$filter['name']],
                $filter['operator'],
                $filter['expression'] ?? null,
                $filter['case_sensitive'] ?? true,
            );
        }

        if ($params->range !== null) {
            $this->applyRange($query, $params->range);
        }

        $grouping = array_values(array_intersect($params->grouping, array_keys($this->columnExpressions)));
        foreach ($grouping as $column) {
            $query->groupByRaw($this->columnExpressions[$column]);
        }

        foreach ($params->sort as $sort) {
            if (isset($sort['name'], $this->columnExpressions[$sort['name']]) && in_array($sort['name'], $selectColumns, true)) {
                $query->orderBy($sort['name'], strtoupper($sort['order'] ?? 'ASC') === 'DESC' ? 'desc' : 'asc');
            }
        }

        // Total row count BEFORE limit/offset (legacy: SQL_CALC_FOUND_ROWS
        // equivalent via Db::getFoundRowsCount()).
        $total = (clone $query)->get()->count();

        if ($params->limit > 0) {
            $query->limit($params->limit)->offset($params->offset);
        }

        $rows = $query->get()->map(fn ($row) => (array) $row)->all();

        $summary = null;
        if ($params->summary) {
            $summary = $this->buildSummary($selectColumns, $allowedCampaignIds);
        }

        return [
            'rows' => $rows,
            'total' => $total,
            'summary' => $summary,
            'meta' => [
                'execution_time' => number_format(microtime(true) - $start, 4),
                'datetime' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * `DB::table($this->table)` with the ACL `campaign_id IN (...)`
     * restriction pre-applied (skipped entirely for AclService::ALLOW_ANY)
     * — every query this class runs (main select, the pre-limit total
     * count, the summary query) must go through this, not raw
     * `DB::table()`, so ACL can't be bypassed on any of those paths.
     *
     * @param  string|array<int, int>  $allowedCampaignIds
     */
    private function baseQuery(string|array $allowedCampaignIds): QueryBuilder
    {
        $query = DB::table($this->table);

        foreach ($this->joins as [$joinTable, $first, $operator, $second]) {
            $query->leftJoin($joinTable, $first, $operator, $second);
        }

        if ($allowedCampaignIds !== AclService::ALLOW_ANY) {
            $query->whereIn($this->table.'.campaign_id', $allowedCampaignIds);
        }

        return $query;
    }

    /** @param  array{interval?: string, from?: string, to?: string}  $range */
    private function applyRange(\Illuminate\Database\Query\Builder $query, array $range): void
    {
        $dateColumn = $this->table === 'conversions' ? 'postback_datetime' : 'datetime';

        if (isset($range['from'])) {
            $query->where($dateColumn, '>=', $range['from']);
        }
        if (isset($range['to'])) {
            $query->where($dateColumn, '<=', $range['to']);
        }
        // Named intervals (today/yesterday/last_7_days/...) intentionally
        // not implemented yet — no caller passes them yet; add when needed.
    }

    /**
     * @param  string[]  $columns
     * @param  string|array<int, int>  $allowedCampaignIds
     */
    private function buildSummary(array $columns, string|array $allowedCampaignIds): array
    {
        $query = $this->baseQuery($allowedCampaignIds);
        foreach ($columns as $column) {
            if (str_contains(strtoupper($this->columnExpressions[$column]), 'SUM(') || str_contains(strtoupper($this->columnExpressions[$column]), 'COUNT(')) {
                $query->selectRaw($this->columnExpressions[$column].' as '.$column);
            }
        }

        return (array) $query->first();
    }
}
