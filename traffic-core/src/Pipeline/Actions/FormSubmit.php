<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\FormSubmit` (application/
 * Traffic/Actions/Predefined/FormSubmit.php) — renders an auto-submitting
 * HTML form to `actionPayload`, with one hidden `<input>` per parsed POST
 * body field. Bypasses the `frm` context mechanism in legacy too, so this
 * does not extend `AbstractAction`. `$_delay` is a hardcoded `0` in legacy
 * (no setter, never overridden) — not configurable, ported as a constant.
 *
 * NOT sanitized here, same as legacy: parsed-body values are interpolated
 * into the `value="..."` attribute with no `htmlspecialchars()` — an
 * existing legacy quirk (this action exists specifically to forward
 * request params verbatim to a POST target), ported as-is rather than
 * silently changed.
 */
class FormSubmit implements ActionHandler
{
    private const DELAY_MS = 0;

    public function execute(Payload $payload): void
    {
        $body = $payload->request->getParsedBody();
        $body = is_array($body) ? $body : [];

        $content = '<!doctype html>' . PHP_EOL;
        $content .= '<head>' . PHP_EOL;
        $content .= "<script>window.onload = function(){\n                setTimeout(function() {\n                    document.forms[0].submit();\n                }, " . self::DELAY_MS . ");\n            };</script>" . PHP_EOL;
        $content .= '</head><body>' . PHP_EOL;
        $content .= '<form action="' . $payload->actionPayload . '" method="POST">';
        foreach ($body as $name => $value) {
            $content .= '<input type="hidden" name="' . $name . '" value="' . $value . '" />' . PHP_EOL;
        }
        $content .= '</form>' . PHP_EOL;
        $content .= '</body></html>' . PHP_EOL;

        $payload->body = $content;
    }
}
