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
 * §3).
 *
 * CORRECTION (2026-09-03): a prior version of `indexAction` returned a
 * `{"message": "..."}` JSON stub, on the premise that "this port is a
 * JSON-only API with no Blade views". That excuse doesn't actually hold —
 * legacy's real `indexAction` (`renderView(".../views/index.phtml")`) is
 * just a static HTML page (Swagger UI, loaded from CDN assets hosted at
 * `admin-api.docs.tds.io`, pointed at `?object=adminApi.spec` as its spec
 * URL) with no server-side templating logic at all — trivially reproduced
 * as a raw HTML string literal, no Blade or view engine needed. Ported
 * verbatim from the real `index.phtml` (same external CDN host — this is
 * the project's own docs CDN, already referenced unmodified by
 * `specAction()` below, not a URL invented for this port), except one
 * fix: legacy's real file has a stray literal `e` character sitting
 * between two `<script>` tags (a copy-paste typo — browsers render it as
 * visible page text), dropped here as a real bug, not a compatibility
 * requirement (nothing consumes this page programmatically to depend on
 * that typo).
 */
class AdminApiController extends Controller
{
    private const SWAGGER_UI_HTML = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin API</title>
    <link rel="stylesheet" type="text/css" href="https://admin-api.docs.tds.io/swagger-ui/swagger-ui.css" >
    <style>
        html
        {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }

        *,
        *:before,
        *:after
        {
            box-sizing: inherit;
        }

        body
        {
            margin:0;
            background: #fafafa;
        }
    </style>
</head>

<body>
<div id="swagger-ui"></div>

<script src="https://admin-api.docs.tds.io/swagger-ui/swagger-ui-bundle.js"></script>
<script src="https://admin-api.docs.tds.io/swagger-ui/swagger-ui-standalone-preset.js"></script>
<link rel="stylesheet" href="https://admin-api.docs.tds.io/assets/theme-tds.css">
<script>
  window.onload = function() {
    // Begin Swagger UI call region
    const ui = SwaggerUIBundle({
      spec: location.host,
      url: "?object=adminApi.spec",
      dom_id: '#swagger-ui',
      deepLinking: true,
      presets: [
        SwaggerUIBundle.presets.apis,
        SwaggerUIStandalonePreset
      ],
      plugins: [
      ],
      layout: "StandaloneLayout",
    })
    window.ui = ui
  }
</script>
</body>
</html>
HTML;

    public function indexAction(Request $request): Response
    {
        return response(self::SWAGGER_UI_HTML)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function specAction(Request $request): Response
    {
        return new RedirectResponse('https://admin-api.docs.tds.io/openapi.yaml');
    }
}
