<?php

/**
 * Port of legacy `gateway.php` + `Traffic\Context\GatewayRedirectContext`
 * + `Traffic\Dispatcher\GatewayRedirectDispatcher` (application/Traffic/
 * Context/GatewayRedirectContext.php, application/Traffic/Dispatcher/
 * GatewayRedirectDispatcher.php) — the receiving end of the `double_meta`
 * action's two-step redirect (see `TrafficCore\Pipeline\Actions\
 * DoubleMeta`). Decodes the JWT `token` query param (signed with a key
 * derived from the CURRENT request's User-Agent — `LpTokenKey`), and
 * performs the real redirect to the URL it carries, from this separate
 * `/gateway.php` origin.
 *
 * `_code()`'s exact meta-refresh + JS redirect HTML is a literal port of
 * `GatewayRedirectDispatcher::_code()`. Error responses (missing token,
 * bad/expired/wrong-UA token) mirror legacy's status codes (500/400)
 * even though the body text isn't byte-identical (legacy's comes from
 * a generic error-response builder not ported here).
 */

require __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use TrafficCore\LpToken\LpTokenKey;

$psr17Factory = new Psr17Factory();
$creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
$request = $creator->fromGlobals();

$token = $request->getQueryParams()['token'] ?? null;

if (empty($token)) {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo 'Empty token';
    return;
}

$userAgent = $request->getHeaderLine('User-Agent');

try {
    $decoded = JWT::decode($token, new Key(LpTokenKey::generateUserKey($userAgent), 'HS256'));
    $url = (string) $decoded->url;

    header('Content-Type: text/html; charset=UTF-8');
    echo "<html>\n"
        . "    <head>\n"
        . "        <meta http-equiv=\"REFRESH\" content=\"1; URL='{$url}'\">\n"
        . "        <script type=\"application/javascript\">window.location = \"{$url}\";</script>\n"
        . "    </head>\n"
        . "</html>";
} catch (\UnexpectedValueException|\DomainException $e) {
    http_response_code(400);
    header('Content-Type: text/html; charset=UTF-8');
    echo 'Bad Request';
}
