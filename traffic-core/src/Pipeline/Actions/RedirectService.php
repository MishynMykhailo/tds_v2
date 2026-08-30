<?php

namespace TrafficCore\Pipeline\Actions;

/**
 * Literal port of legacy `Traffic\Actions\Service\RedirectService`
 * (application/Traffic/Actions/Service/RedirectService.php) — three tiny
 * HTML/JS snippet builders shared by several action types (js*, meta,
 * double_meta, remote). No logic changes, byte-for-byte template port.
 */
final class RedirectService
{
    public static function scriptRedirect(string $url): string
    {
        return "function process() {\n                window.location = \"" . $url . "\";\n            }\n            window.onerror = process;\n            process();\n        ";
    }

    public static function frameRedirect(string $url): string
    {
        return "<script type=\"application/javascript\">\n            function process() {\n                top.location = \"" . $url . "\";\n            }\n\n            window.onerror = process;\n\n            if (top.location.href != window.location.href) {\n                process()\n            }\n        </script>";
    }

    /**
     * @param array{delay?:int,no_referrer?:bool} $options
     */
    public static function metaRedirect(string $url, array $options = []): string
    {
        $options = array_merge(['delay' => 1, 'no_referrer' => false], $options);
        $metas = ['<meta http-equiv="REFRESH" content="' . $options['delay'] . '; URL=\'' . $url . '\'">'];
        if ($options['no_referrer']) {
            $metas[] = '<meta name="referrer" content="no-referrer" />';
        }
        $metas = implode("\n    ", $metas);

        return "<html lang=\"en\">\n  <head>\n    " . $metas . "\n    <title></title>\n  </head>\n</html>";
    }
}
