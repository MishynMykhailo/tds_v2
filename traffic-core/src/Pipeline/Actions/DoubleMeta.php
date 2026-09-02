<?php

namespace TrafficCore\Pipeline\Actions;

use Firebase\JWT\JWT;
use TrafficCore\LpToken\LpTokenKey;
use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\DoubleMeta` (application/
 * Traffic/Actions/Predefined/DoubleMeta.php) — a two-step redirect: this
 * action never redirects straight to the offer/landing URL, it redirects
 * to traffic-core's own `/gateway.php?frm=dm&token=<jwt>` (see that file
 * and `LpTokenKey`), which decodes the JWT and does the real redirect
 * from a different, gateway-only origin. The point (per legacy naming
 * and the offer field it's kept on) is hiding the real destination URL
 * from the referrer/page source of the intermediate hop.
 *
 * `_executeDefault()`/`_executeForFrame()`/`_executeForScript()` all
 * build the same signed gateway URL, differing only in which
 * `RedirectService` snippet wraps it — literal port.
 */
class DoubleMeta extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $payload->body = RedirectService::metaRedirect($this->gatewayUrl($payload));
    }

    protected function executeForFrame(Payload $payload): void
    {
        $payload->body = RedirectService::frameRedirect($this->gatewayUrl($payload));
    }

    protected function executeForScript(Payload $payload): void
    {
        $payload->headers['Content-Type'] = 'application/javascript';
        $payload->body = RedirectService::scriptRedirect($this->gatewayUrl($payload));
    }

    private function gatewayUrl(Payload $payload): string
    {
        $token = JWT::encode(
            ['url' => (string) $payload->actionPayload],
            LpTokenKey::generateUserKey($payload->signal['userAgent'] ?? ''),
            'HS256'
        );

        $uri = $payload->request->getUri();
        $host = preg_replace('#^www\.#i', '', $uri->getHost());

        return $uri->getScheme() . '://' . $host . '/gateway.php?frm=dm&token=' . $token;
    }
}
