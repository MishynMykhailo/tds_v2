<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\JsForScript` (application/
 * Traffic/Actions/Predefined/JsForScript.php) — `_executeDefault()`
 * delegates to `_executeForScript()` (same in legacy).
 *
 * `executeForFrame()`'s content-type is `"html/text"` verbatim — a literal
 * typo in the legacy source (should presumably be `text/html`), ported
 * as-is rather than silently corrected.
 */
class JsForScript extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $this->executeForScript($payload);
    }

    protected function executeForScript(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->headers['Content-Type'] = 'application/javascript';
        $payload->body = RedirectService::scriptRedirect($url);
    }

    protected function executeForFrame(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->headers['Content-Type'] = 'html/text'; // verbatim legacy typo, see docblock
        $payload->body = RedirectService::frameRedirect($url);
    }
}
