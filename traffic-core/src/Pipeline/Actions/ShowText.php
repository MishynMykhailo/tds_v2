<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\ShowText` (application/
 * Traffic/Actions/Predefined/ShowText.php, alias `echo` — not ported, see
 * ShowHtml's docblock) — renders `actionPayload` as plain text.
 *
 * Legacy's `_execute()` sets `Content-Type: text/plain` BEFORE dispatching
 * to a context (so `_executeDefault()`/`_executeForFrame()` both keep it —
 * only `_executeForScript()` overrides it to `application/javascript`);
 * `execute()` here mirrors that by setting the header first, then
 * delegating to `AbstractAction`'s context dispatch.
 *
 * NOT ported: `processMacros()` (raw content, see AbstractAction's
 * docblock header note).
 */
class ShowText extends AbstractAction
{
    public function execute(Payload $payload): void
    {
        $payload->headers['Content-Type'] = 'text/plain; charset=utf-8';
        parent::execute($payload);
    }

    protected function executeDefault(Payload $payload): void
    {
        $payload->body = (string) $payload->actionPayload;
    }

    protected function executeForFrame(Payload $payload): void
    {
        $payload->body = (string) $payload->actionPayload;
    }

    protected function executeForScript(Payload $payload): void
    {
        $payload->headers['Content-Type'] = 'application/javascript';
        $payload->body = (string) $payload->actionPayload;
    }
}
