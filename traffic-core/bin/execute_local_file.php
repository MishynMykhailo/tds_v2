<?php

/**
 * Sandbox worker for the `local_file` action (see
 * `TrafficCore\Pipeline\Actions\LocalFileSandbox`) — literal port of
 * legacy `bin/execute_script.php` (application/bin/execute_script.php),
 * invoked exactly the same way: run under a real `php-cgi` SAPI process
 * spawned via `proc_open`, with `SCRIPT_FILENAME` pointed at this file
 * (`REDIRECT_STATUS`/`REQUEST_METHOD=POST`/`REMOTE_ADDR=127.127.127.127`
 * env vars set by the parent, see `LocalFileSandbox::execute()` —
 * matches `Core\Sandbox\Sandbox::execute()`'s `$env` array field for
 * field). Request data (the real `$_SERVER`/`$_GET`/`$_POST`/`$_COOKIE`
 * captured by traffic-core's own PSR-7 request, NOT whatever php-cgi
 * derives from the placeholder env above) arrives as a single urlencoded
 * `params` POST field containing JSON — same wire shape as legacy's
 * `"params=" . urlencode(json_encode($params))`, JSON instead of
 * legacy's PHP-`serialize()`+base64 for the payload only (legacy's OWN
 * `_getExecParams()` already uses `json_encode`/`json_decode` for this
 * exact field, not serialize — confirmed by reading it, so this isn't a
 * format downgrade).
 *
 * `php-cgi` is a real binary in this project's `tds2-php-dev` image
 * (built from the same PHP source tree as the image's `php` CLI SAPI —
 * see `deploy/Dockerfile.dev-php`'s dedicated build step and comment for
 * why Debian's own `php8.4-cgi` .deb couldn't be used instead) — full
 * technical parity with legacy's execution engine, not a substitute.
 *
 * The `REMOTE_ADDR === "127.127.127.127"` gate IS meaningful here, same
 * as legacy: this script's `SCRIPT_FILENAME` sits outside `public/`, but
 * if a webserver config ever pointed at it directly this still refuses
 * anything not carrying the parent process's own placeholder IP.
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

if (($_SERVER['REMOTE_ADDR'] ?? null) !== '127.127.127.127') {
    header('HTTP/1.1 403 Forbidden');
    echo '403 Forbidden';
    exit;
}

error_reporting(22517); // literal port of legacy's execute_script.php level

parse_str((string) file_get_contents('php://input'), $result);
$params = json_decode((string) ($result['params'] ?? ''), true);
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
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Internal error';
    exit;
}

chdir(dirname($filepath));
include $filepath;
