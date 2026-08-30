<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\BlankReferrer` (application/
 * Traffic/Actions/Predefined/BlankReferrer.php) — meta-refresh redirect
 * with `no_referrer` set and `delay=0` (strips the browser's `Referer`
 * header, unlike a plain HTTP redirect which can't). Legacy never
 * overrides `_executeForFrame()`, so that embed context correctly falls
 * back to the generic stub, matching legacy.
 *
 * `executeForScript()` NOT ported — same `AdsParser` gap as `Meta`, see
 * that class's docblock.
 */
class BlankReferrer extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $payload->body = RedirectService::metaRedirect(
            (string) $payload->actionPayload,
            ['delay' => 0, 'no_referrer' => true]
        );
    }
}
