<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Port of legacy `Component\Postback\Controller\PostbackTemplatesController`
 * (object=postbackTemplates). Delegates to
 * `Component\AffiliateNetworks\Repository\NetworkTemplatesRepository`,
 * which — same as `TrafficSourceTemplateRepository` (see
 * TrafficSourceTemplatesController) — has `const PATH = NULL` with no
 * overriding subclass anywhere in the old codebase, so it's permanently
 * empty even on the live old backend (cut vendor-supplied template data,
 * see docs/PORTING_LOG.md). Ported faithfully as empty, not stubbed
 * differently.
 */
class PostbackTemplatesController extends Controller
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
