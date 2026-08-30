<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\Iframe` (application/Traffic/
 * Actions/Predefined/Iframe.php) — wraps `actionPayload` in a full-page
 * `<iframe>`.
 *
 * `executeForFrame()` is a literal, slightly odd port: legacy sets a
 * `Location` header via `addHeader()` (NOT `redirect()`) and only forces
 * status 302 when a `kversion` query param is present and
 * `version_compare($kversion, "3.4") >= 0` — otherwise the response stays
 * 200 with a `Location` header a normal browser would ignore. This only
 * makes sense for a JS/AJAX client reading the header manually (an old
 * `kclient.js` version-gate), which is consistent with this branch only
 * being reachable via the embed `frm` mechanism in the first place. Ported
 * as-is, not "fixed" to always redirect — changing it would diverge from
 * a real, if narrow, legacy behavior.
 */
class Iframe extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->body = "<!DOCTYPE html>\n        <html>\n        <head>\n        <meta name=\"viewport\" content=\"width=device-width, initial-scale=1, maximum-scale=1\" />\n        </head>   \n        <style type=\"text/css\">\n        body, html{\n            margin: 0;\n            padding: 0;\n            width: 100%;\n            height: 100%;\n            overflow-y: auto;\n            overflow-x: hidden;\n            -webkit-overflow-scrolling:touch\n        }\n        iframe {\n                width: 100%;\n                height:100%;\n                min-height: 10000px;\n                border: 0;\n            }\n        </style>\n        <body><iframe src=\"" . $url . "\"></iframe></body>\n        </html>";
    }

    protected function executeForFrame(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->headers['Location'] = $url;

        $kversion = $payload->request->getQueryParams()['kversion'] ?? null;
        if ($kversion !== null && version_compare((string) $kversion, '3.4') >= 0) {
            $payload->statusCode = 302;
        }
    }
}
