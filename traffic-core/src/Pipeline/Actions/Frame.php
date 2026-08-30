<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\Frame` (application/Traffic/
 * Actions/Predefined/Frame.php) — wraps `actionPayload` in a fullscreen
 * HTML `<frameset>`. Legacy never overrides `_executeForFrame()`/
 * `_executeForScript()` for this class (confirmed by reading the file —
 * only `_executeDefault()` exists), so those embed contexts correctly fall
 * back to `AbstractAction`'s generic "incompatible" stub here too — not a
 * gap introduced by this port.
 *
 * See `AbstractAction`'s docblock for the confirmed live-verified bug fix
 * that makes `executeDefault()` reachable at all for a plain click (it's
 * dead code in the current legacy app).
 */
class Frame extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->body = "<html>\n            <head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1, maximum-scale=1\" /></head>\n            <frameset rows=\"100%\"><frame src=\"" . $url . "\"></frameset></html>";
    }
}
