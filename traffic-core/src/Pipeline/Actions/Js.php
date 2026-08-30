<?php

namespace TrafficCore\Pipeline\Actions;

use TrafficCore\Pipeline\Payload;

/**
 * Port of legacy `Traffic\Actions\Predefined\Js` (application/Traffic/
 * Actions/Predefined/Js.php) — JS-based redirect (`top.location`/
 * `window.location`), with an HTML fallback link for the default context.
 */
class Js extends AbstractAction
{
    protected function executeDefault(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $js = self::javascriptRedirect($url);
        $payload->body = "<html>\n        <head>\n            <script type=\"application/javascript\">" . $js . "</script>\n        </head>\n        <body>\n            The Document has moved <a href=\"" . $url . "\">here</a>\n        </body>\n        </html>";
    }

    protected function executeForScript(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->headers['Content-Type'] = 'application/javascript';
        $payload->body = self::javascriptRedirect($url);
    }

    protected function executeForFrame(Payload $payload): void
    {
        $url = (string) $payload->actionPayload;
        $payload->body = RedirectService::frameRedirect($url);
    }

    private static function javascriptRedirect(string $url): string
    {
        return "\n                function process() {\n                   if (window.location !== window.parent.location ) {\n                      top.location = \"" . $url . "\";\n                   } else {\n                      window.location = \"" . $url . "\";\n                   }\n                }\n                window.onerror = process;\n                process();";
    }
}
