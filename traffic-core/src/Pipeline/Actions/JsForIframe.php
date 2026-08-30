<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\JsForIframe` (application/
 * Traffic/Actions/Predefined/JsForIframe.php) — `_executeDefault()`
 * literally delegates to `_executeForFrame()` (same in legacy); no
 * `_executeForScript()` override, so that embed context correctly falls
 * back to `AbstractAction`'s generic stub, matching legacy.
 */
class JsForIframe extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $this->executeForFrame($payload);
    }

    protected function executeForFrame(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->body = RedirectService::frameRedirect($url);
    }
}
