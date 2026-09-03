<?php

namespace App\Services\Grid;

use Illuminate\Http\Request;

/**
 * Port of legacy `Component\Grid\QueryParams\QueryParams`
 * (application/Component/Grid/QueryParams/QueryParams.php) — parses the
 * POST body of a `withStats`/`reports.build`/`conversions.log`-style
 * request into a structured object. Contract:
 * docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §9.2.
 *
 * NOT the query EXECUTION (that's GridBuilder, built on top of this) — this
 * class only parses/validates the shape of the incoming request body.
 */
class QueryParams
{
    /** @var string[] */
    public array $columns = [];

    /** @var string[] "grouping" (alias "dimensions") */
    public array $grouping = [];

    /** @var string[] */
    public array $metrics = [];

    /** @var array<int, array{name: string, order: string}> */
    public array $sort = [];

    /** @var array<int, array{name: string, operator: string, expression: mixed, case_sensitive?: bool}> */
    public array $filters = [];

    /** @var array{interval?: string, from?: string, to?: string}|null */
    public ?array $range = null;

    public int $limit = 0;

    public int $offset = 0;

    public bool $summary = false;

    public string $format = 'array';

    public static function fromRequest(Request $request): self
    {
        $body = $request->json()->all();
        if (empty($body) && $request->getContent() !== '') {
            // Mirrors the rest of this codebase's legacy body-parsing
            // convention (query-string body without JSON Content-Type).
            parse_str($request->getContent(), $body);
        }

        $params = new self();
        $params->columns = self::toStringArray($body['columns'] ?? []);
        $params->grouping = self::toStringArray($body['grouping'] ?? $body['dimensions'] ?? []);
        $params->metrics = self::toStringArray($body['metrics'] ?? []);
        $params->sort = is_array($body['sort'] ?? null) ? $body['sort'] : [];
        $params->filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
        $params->range = is_array($body['range'] ?? null) ? $body['range'] : null;
        $params->limit = isset($body['limit']) ? max(0, (int) $body['limit']) : 0;
        $params->offset = isset($body['offset']) ? max(0, (int) $body['offset']) : 0;
        $params->summary = filter_var($body['summary'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $params->format = is_string($body['format'] ?? null) ? $body['format'] : 'array';

        // REAL BUG, found live against legacy port 8090 (2026-09-03):
        // `hasRangeOrLimit()` below existed but was never actually called
        // anywhere in this codebase — every one of the 7 controllers that
        // build a QueryParams (reports.build, conversions.log, and the 5
        // withStats actions) silently accepted a bare request with
        // neither `range` nor `limit` and ran an unbounded query. Real
        // legacy `QueryParams::__construct()` throws a generic `Error`
        // ("You must provide \"range\" or \"limit\"") right here, at
        // parse time (application/Component/Grid/QueryParams/
        // QueryParams.php:163) - confirmed live for BOTH conversions.log
        // and campaigns.withStats (500 on both in real legacy). Same
        // "generic Error -> catch-all handler -> 500, plain text, not
        // JSON" shape already established elsewhere in this codebase
        // (see SettingsController::updateAction()'s "Must be post
        // request" for the identical pattern).
        if (! $params->hasRangeOrLimit()) {
            abort(500, 'You must provide "range" or "limit"');
        }

        return $params;
    }

    /** §9.2: "хотя бы одно из range/limit обязательно". */
    public function hasRangeOrLimit(): bool
    {
        return $this->range !== null || $this->limit > 0;
    }

    private static function toStringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}
