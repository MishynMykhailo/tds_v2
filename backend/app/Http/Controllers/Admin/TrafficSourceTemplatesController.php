<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\TrafficSources\Controller\TrafficSourceTemplatesController`
 * (object=trafficSourceTemplates). Legacy's
 * `TrafficSourceTemplateRepository::PATH = NULL` with no overriding
 * subclass anywhere in the old codebase — `is_readable(NULL)` is always
 * false, so `getData()` always returns `[]` even on the live old backend.
 * This is one of the external-vendor-data files that were never delivered
 * (same category as the cut ip2location/bot-db binaries — see
 * docs/legacy-reference/TODO_IMPROVEMENTS.md and RISKY_FIXES.md in the old
 * repo). Ported faithfully as permanently-empty, not stubbed differently.
 */
class TrafficSourceTemplatesController extends Controller
{
    public function indexAction(Request $request): array
    {
        return [];
    }

    public function findAction(Request $request): mixed
    {
        return null;
    }
}
