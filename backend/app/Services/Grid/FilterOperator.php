<?php

namespace App\Services\Grid;

/**
 * Port of legacy `Component\Grid\Query\FilterItem` operator constants.
 * Contract: docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §9.2. Case-insensitive on input, normalized to uppercase (legacy:
 * "регистронезависимо, приводится к верхнему регистру").
 */
final class FilterOperator
{
    public const EQUALS = 'EQUALS';

    public const NOT_EQUAL = 'NOT_EQUAL';

    public const GREATER_THAN = 'GREATER_THAN';

    public const EQUALS_OR_GREATER_THAN = 'EQUALS_OR_GREATER_THAN';

    public const LESS_THAN = 'LESS_THAN';

    public const EQUALS_OR_LESS_THAN = 'EQUALS_OR_LESS_THAN';

    public const MATCH_REGEXP = 'MATCH_REGEXP';

    public const NOT_MATCH_REGEXP = 'NOT_MATCH_REGEXP';

    public const BEGINS_WITH = 'BEGINS_WITH';

    public const IP_BEGINS_WITH = 'IP_BEGINS_WITH';

    public const ENDS_WITH = 'ENDS_WITH';

    public const CONTAINS = 'CONTAINS';

    public const NOT_CONTAIN = 'NOT_CONTAIN';

    public const IN_LIST = 'IN_LIST';

    public const NOT_IN_LIST = 'NOT_IN_LIST';

    public const BETWEEN = 'BETWEEN';

    public const IS_SET = 'IS_SET';

    public const IS_NOT_SET = 'IS_NOT_SET';

    public const IS_TRUE = 'IS_TRUE';

    public const IS_FALSE = 'IS_FALSE';

    public const HAS_LABEL = 'HAS_LABEL';

    public const HAS_NOT_LABEL = 'HAS_NOT_LABEL';

    /**
     * Applies a single filter clause to a query builder. `$column` must
     * already be resolved to a real, safe SQL column/expression by the
     * caller (GridBuilder) — this method does not do column-name
     * whitelisting itself.
     */
    public static function apply(\Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder $query, string $column, string $operator, mixed $expression, bool $caseSensitive = true): void
    {
        $operator = strtoupper($operator);

        // §9.2: filter on ip/ip_mask* with an operator other than
        // IP_BEGINS_WITH auto-converts the string IP to ip2long.
        if ((str_contains($column, 'ip') || str_starts_with($column, 'ip_mask')) && $operator !== self::IP_BEGINS_WITH && is_string($expression)) {
            $expression = ip2long($expression) ?: $expression;
        }

        match ($operator) {
            self::EQUALS => $query->where($column, '=', $expression),
            self::NOT_EQUAL => $query->where($column, '!=', $expression),
            self::GREATER_THAN => $query->where($column, '>', $expression),
            self::EQUALS_OR_GREATER_THAN => $query->where($column, '>=', $expression),
            self::LESS_THAN => $query->where($column, '<', $expression),
            self::EQUALS_OR_LESS_THAN => $query->where($column, '<=', $expression),
            self::MATCH_REGEXP => $query->where($column, 'REGEXP', (string) $expression),
            self::NOT_MATCH_REGEXP => $query->where($column, 'NOT REGEXP', (string) $expression),
            self::BEGINS_WITH, self::IP_BEGINS_WITH => $query->where($column, 'like', $expression.'%'),
            self::ENDS_WITH => $query->where($column, 'like', '%'.$expression),
            self::CONTAINS => $query->where($column, 'like', '%'.$expression.'%'),
            self::NOT_CONTAIN => $query->where($column, 'not like', '%'.$expression.'%'),
            self::IN_LIST => $query->whereIn($column, (array) $expression),
            self::NOT_IN_LIST => $query->whereNotIn($column, (array) $expression),
            self::BETWEEN => $query->whereBetween($column, (array) $expression),
            self::IS_SET => $query->whereNotNull($column),
            self::IS_NOT_SET => $query->whereNull($column),
            self::IS_TRUE => $query->where($column, '=', true),
            self::IS_FALSE => $query->where($column, '=', false),
            // HAS_LABEL/HAS_NOT_LABEL depend on the not-yet-ported Labels
            // module (see docs/PORTING_LOG.md) — no-op for now rather than
            // throwing, so grids that don't use label filters still work.
            self::HAS_LABEL, self::HAS_NOT_LABEL => null,
            default => throw new \InvalidArgumentException("Unknown filter operator: {$operator}"),
        };
    }
}
