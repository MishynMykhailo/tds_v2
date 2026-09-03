<?php

/**
 * OPTIONAL router script for PHP's built-in dev server — needed ONLY to
 * locally test the pretty-URL postback form (`/{key}/postback`, see
 * `PostbackAuthService::findPathKey()`'s docblock). Every other entry
 * point in this project is served WITHOUT a router script on purpose
 * (`php -S host:port -t public`, no trailing script argument — see
 * docs/PORTING_LOG.md's Phase 7 "Операционная находка": passing
 * `public/index.php` as an explicit router forwards ALL requests
 * through it, breaking every other literal-filename entry point like
 * `gateway.php`/`preview.php`/`postback.php` itself). This file
 * preserves that behavior for everything except the one new pretty-URL
 * pattern: `return false` for any other request tells PHP's built-in
 * server to fall back to its normal "serve this file directly" handling
 * — see https://www.php.net/manual/en/features.commandline.webserver.php.
 *
 * Usage: `php -S 0.0.0.0:PORT -t public public/router.php` (note: THIS
 * ONE entry point, unlike every other, DOES take an explicit router
 * script argument).
 *
 * Production does NOT use this file at all — a real webserver rewrite
 * does the equivalent job, see `deploy/nginx-traffic-core.conf.example`.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path !== null && preg_match('#^/([^/]+)/postback/?$#', $path, $matches) === 1) {
    // Nothing else to do here — postback.php resolves the key itself via
    // PostbackAuthService::findPathKey(), which reads $_SERVER['REQUEST_URI']
    // through the PSR-7 request, not a value this router needs to inject.
    require __DIR__.'/postback.php';

    return true;
}

return false;
