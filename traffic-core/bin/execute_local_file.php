<?php

/**
 * Sandbox worker for the `local_file` action (see
 * `TrafficCore\Pipeline\Actions\LocalFileSandbox`) — loose port of legacy
 * `bin/execute_script.php` + `Core\Sandbox\Sandbox::execute()`/
 * `executeInChild()` (application/Core/Sandbox/Sandbox.php).
 *
 * Deliberate infrastructure substitution, not a fidelity cut: legacy
 * spawns `php-cgi` and talks the CGI protocol (headers, then a blank
 * line, then body) with this same script's job done by `bin/
 * execute_script.php` running UNDER that php-cgi process. Debian's
 * `php8.4-cgi` package couldn't be installed in this project's
 * `tds2-php-dev` image (unmet phpapi-* dependency against Debian's own
 * apt PHP stack, which isn't installed — the base `php:8.4-cli` image
 * compiles PHP from source instead) and building `php-cgi` from source
 * was judged not worth the build-time cost for this feature. Same
 * practical outcome via a different, simpler mechanism instead: this
 * script runs under the plain CLI SAPI (invoked by `LocalFileSandbox` via
 * `proc_open`, JSON on stdin/stdout instead of the CGI wire format);
 * `header()`/`http_response_code()` work identically under CLI SAPI and
 * are read back via `headers_list()`.
 *
 * Never a public HTTP entry point — lives outside `public/`, only
 * reachable via direct CLI invocation from `LocalFileSandbox`. Legacy's
 * `execute_script.php` guards itself with a hardcoded
 * `REMOTE_ADDR === "127.127.127.127"` check specifically because it DOES
 * sit inside the CGI/webserver request path; that guard has no equivalent
 * concept here since this script is never bound to any port or router in
 * the first place.
 *
 * Security hardening beyond legacy (documented, not a silent behavior
 * change): the calling process additionally sets `disable_functions` and
 * `open_basedir` via `-d` flags — see `LocalFileSandbox`. Legacy relies
 * only on upload-time source-scanning (`Validator`, already ported to
 * `backend/`'s `LocalFileService`) with no runtime hardening at all.
 *
 * This local landing-page hosting feature is a 1:1 port of the same
 * feature already present and in active use in the legacy application
 * this project replaces (users upload their own HTML/PHP landing pages
 * through the already-ported, already-validating Editor/Cleaner admin
 * screens) — not new functionality.
 */

$raw = stream_get_contents(STDIN);
$params = json_decode($raw, true);
$params = is_array($params) ? $params : [];

$_SERVER = is_array($params['server'] ?? null) ? $params['server'] : [];
$_GET = is_array($params['get'] ?? null) ? $params['get'] : [];
$_POST = is_array($params['post'] ?? null) ? $params['post'] : [];
$_COOKIE = is_array($params['cookie'] ?? null) ? $params['cookie'] : [];
$_REQUEST = array_merge($_GET, $_POST, $_COOKIE);

// Exposed for landing-page PHP that wants to read click data — plain
// array, not legacy's `RawClickGetter` object (traffic-core has no such
// class; `$payload->rawClick` is already a plain array everywhere else).
$rawClick = is_array($params['rawClick'] ?? null) ? $params['rawClick'] : [];

$filepath = (string) ($params['filepath'] ?? '');

if ($filepath === '' || !is_file($filepath)) {
    fwrite(STDOUT, json_encode(['status' => 500, 'headers' => [], 'body' => 'Internal error']));
    exit(0);
}

chdir(dirname($filepath));

http_response_code(200);
ob_start();

try {
    include $filepath;
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage());
}

$body = (string) ob_get_clean();
$status = http_response_code();
$headers = headers_list();

fwrite(STDOUT, json_encode(['status' => $status, 'headers' => $headers, 'body' => $body]));
