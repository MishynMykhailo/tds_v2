<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\ShowHtml` (application/
 * Traffic/Actions/Predefined/ShowHtml.php) — renders `actionPayload`
 * (aliases: `build_html`/`return_html` in legacy's registry — traffic-core
 * doesn't port aliases, only the canonical `show_html` key) directly as
 * HTML, wrapping it in a minimal shell if it isn't already a full
 * document. `_executeDefault()` literally delegates to
 * `_executeForFrame()` in legacy — same here.
 *
 * `executeForScript()` NOT ported — same `AdsParser` gap as `Meta`, see
 * that class's docblock.
 *
 * NOT ported: `processMacros()` on the payload — content is raw,
 * unsubstituted (see AbstractAction's docblock header note, applies
 * project-wide).
 */
class ShowHtml extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $this->executeForFrame($payload);
    }

    protected function executeForFrame(Payload $payload): void
    {
        $payload->headers['Content-Type'] = 'text/html';
        $code = (string) $payload->actionPayload;
        if (stripos($code, '<html') === false) {
            $code = '<html><style>body{margin:0}</style><body>' . $code . '</body></html>';
        }
        $payload->body = $code;
    }
}
