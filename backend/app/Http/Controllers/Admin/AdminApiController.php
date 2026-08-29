<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of legacy `Component\AdminApi\Controller\AdminApiController`
 * (object=adminApi — NOT the same thing as the REST `/admin_api/vN/...`
 * layer, see docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §3). Legacy `indexAction` rendered a static HTML docs page
 * (`views/index.phtml`) — this port is a JSON-only API with no Blade views,
 * so it returns a minimal JSON pointer instead of HTML; `specAction`
 * redirects to an external OpenAPI spec exactly like legacy did.
 */
class AdminApiController extends Controller
{
    public function indexAction(Request $request): array
    {
        return [
            'message' => 'Admin API documentation. See specAction for the OpenAPI spec.',
        ];
    }

    public function specAction(Request $request): Response
    {
        return new RedirectResponse('https://admin-api.docs.tds.io/openapi.yaml');
    }
}
