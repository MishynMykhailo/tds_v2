<?php

/**
 * traffic-core incoming-postback entry point — port of legacy
 * `Traffic\Dispatcher\PostbackDispatcher` (application/Traffic/Dispatcher/
 * PostbackDispatcher.php) + `Traffic\Context\PostbackContext`
 * (application/Traffic/Context/PostbackContext.php, a thin bootstrap
 * wrapper with nothing to port — no cookie/session/error-handler
 * machinery exists in traffic-core).
 *
 * This is a SEPARATE entry point from `public/index.php` (the click
 * pipeline) — nothing here touches `Payload`/`PipelineRunner`/
 * `BuildRawClickStage` or any click-pipeline class.
 *
 * Flow (mirrors `PostbackDispatcher::dispatch()`):
 *  1. Find the secret key — `PostbackAuthService::findKey()` (the two
 *     query-based mechanisms) OR, as a fallback, `findPathKey()`
 *     (pretty-URL `/{key}/postback` form — see that method's docblock:
 *     a prior version of THIS docblock claimed this had no legacy
 *     equivalent, which was wrong. `Core\Router\TrafficRouter` already
 *     routes this exact path shape to `PostbackContext` natively,
 *     verified live against legacy port 8090. Kept as a real port, just
 *     discovered after the fact; needs a webserver rewrite in front to
 *     actually reach this file with that shape of URL, see
 *     `deploy/nginx-traffic-core.conf.example` and `public/router.php`
 *     for local dev) — then validate it against
 *     `settings.postback_key` (`PostbackAuthService::isValid()`).
 *     Wrong/missing key -> reject immediately, no processing at all,
 *     matching legacy's own ordering (secret checked before the request
 *     body is even parsed into a `Postback`).
 *  2. Build a `Postback` from ALL request params (GET+POST merged, GET
 *     wins on collision — same merge convention as `TrafficCore\Pipeline\
 *     Signal::fromRequest()`).
 *  3. `PostbackProcessor::process()` — find-or-update the conversion +
 *     click by sub_id (see that class's docblock for the exact ported/
 *     skipped stage-by-stage mapping).
 *  4. On success, fire outbound S2S postbacks
 *     (`OutboundPostbackService::sendFor()`) — best-effort, wrapped in its
 *     own try/catch internally, NEVER allowed to change this response.
 *  5. Respond per `?return=` — literal port of `_updateBody()`'s 3
 *     branches (`jsonp` / `gif` / plain text), including the exact
 *     legacy 1x1 transparent GIF constant (`PostbackDispatcher::PIXEL`).
 *
 * DEVIATION (documented, not a guess): legacy's `dispatch()` has what
 * looks like a real bug — `_updateBody()` is only ever called (a) on the
 * wrong-secret-key branch (with `$return` hardcoded to its 2-arg-call
 * default of `NULL`, i.e. wrong-key responses ALWAYS ignore `?return=`
 * and are always plain text — ported faithfully below, see the
 * `!$authService->isValid()` branch) and (b) inside the `NotFoundError`
 * catch. The success path and the `PostbackError` catch both compute a
 * `$message` string and then fall off the end of the method WITHOUT ever
 * calling `_updateBody()` — meaning legacy's real HTTP response body for
 * a normal accepted-or-rejected postback is empty/whatever the framework
 * defaults to, not the `$message` text a network integration would
 * actually want to see. This port fixes that: the success and
 * `PostbackException` paths below DO call the equivalent of
 * `_updateBody($response, $message, $return)`, using the request's own
 * `return` param — this is what makes the `jsonp`/`gif`/plain-text
 * response formats the task calls out as "real integrations affiliate
 * networks depend on" actually reachable at all.
 */

require __DIR__ . '/../vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\Postback\OutboundPostbackService;
use TrafficCore\Postback\Postback;
use TrafficCore\Postback\PostbackAuthService;
use TrafficCore\Postback\PostbackException;
use TrafficCore\Postback\PostbackProcessor;

/** Legacy `PostbackDispatcher::PIXEL` — copied verbatim, do not regenerate. */
const POSTBACK_PIXEL_BASE64 = 'R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==';

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

$query = $request->getQueryParams();
$body = $request->getParsedBody();
$body = is_array($body) ? $body : [];
// GET wins on key collision — same convention as TrafficCore\Pipeline\Signal::fromRequest().
$params = array_merge($body, $query);

$returnFormat = isset($query['return']) ? (string) $query['return'] : null;

$authService = new PostbackAuthService();
// Query-based key first (the two real legacy mechanisms), pretty-URL
// path key (`/{key}/postback`, see PostbackAuthService::findPathKey()'s
// docblock — also a genuine legacy mechanism, TrafficRouter routes it
// natively) as a fallback.
$key = $authService->findKey($request) ?? $authService->findPathKey($request);

header('Cache-Control: no-cache, no-store, must-revalidate');

if (!$authService->isValid($key)) {
    $message = 'Incorrect postback code (' . ($key ?? '') . ' in ' . $request->getUri()->getPath() . ')';
    error_log('[postback] ' . $message);
    // DEVIATION: legacy's Response::build() default status (likely 200 —
    // GuzzleHttp\Psr7\Response's own default) is left as-is by this
    // branch in the original source. This port sends 403 explicitly for
    // a rejected/unauthorized postback, which is clearer for real
    // integrations and monitoring than a bare 200 with an error string
    // body as the only signal.
    http_response_code(403);
    // Wrong-key branch ignores `?return=` — literal port, see file docblock.
    respondPlainText($message);
    exit;
}

$postback = Postback::buildFromParams($params);

try {
    $processor = new PostbackProcessor();
    $result = $processor->process($postback);

    if ($result->status !== 'ignore') {
        (new OutboundPostbackService())->sendFor($result);
    }

    $message = 'Success';
} catch (PostbackException $e) {
    error_log('[postback] ' . $e->getMessage());
    $message = $e->getMessage();
}

respond($message, $returnFormat);

/**
 * Port of `PostbackDispatcher::_updateBody()`'s `jsonp`/`gif`/default
 * branches, for the success and `PostbackException` paths (both of which
 * honor `?return=`, per this file's docblock).
 */
function respond(string $message, ?string $return): void
{
    switch ($return) {
        case 'jsonp':
            header('Content-Type: application/javascript; charset=UTF-8');
            echo 'KTracking && KTracking.response("' . htmlspecialchars($message, ENT_QUOTES) . '")';

            return;
        case 'gif':
            header('Content-Type: image/gif');
            echo base64_decode(POSTBACK_PIXEL_BASE64);

            return;
        default:
            // Literal port of legacy's odd fallthrough: a non-empty,
            // non-jsonp/gif `?return=` value is echoed back verbatim
            // (htmlentities-escaped) INSTEAD OF $message; only an empty/
            // absent `return` actually shows the real result message.
            if (!empty($return)) {
                header('Content-Type: text/plain; charset=UTF-8');
                echo htmlentities($return);

                return;
            }
            respondPlainText($message);
    }
}

function respondPlainText(string $message): void
{
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
}
