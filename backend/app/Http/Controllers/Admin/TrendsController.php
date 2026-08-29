<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\Trends\Controller\TrendsController`
 * (object=trends). Legacy `indexAction` is a literal no-op (empty method
 * body, confirmed by reading the source — not a decompiler artifact, that
 * really is the whole method) — replicated as-is. `TrendsDefinition`
 * extends `Reports\Grid\ReportDefinition` with only `$_rangeIntervals =
 * NULL` overridden — i.e. it's the exact same column set as
 * `ReportsController::definitionAction()`, just a different `url`. Delegate
 * to that instead of duplicating the column list.
 */
class TrendsController extends Controller
{
    public function __construct(
        private readonly ReportsController $reportsController,
    ) {}

    public function indexAction(Request $request): mixed
    {
        return null;
    }

    public function definitionAction(Request $request): array
    {
        $definition = $this->reportsController->definitionAction($request);
        $definition['url'] = '?object=trends.index';

        return $definition;
    }
}
