<?php

namespace TrafficCore\Pipeline\Actions;

/**
 * Port of three of the four HTML-rewriting methods on legacy
 * `Traffic\Actions\CurlService` (application/Traffic/Actions/
 * CurlService.php) — used exclusively by `local_file`'s `PageWrapper`
 * (confirmed by reading `CurlService`'s own callers: `curl`/`remote`
 * actions never call these, only `PageWrapper::_adaptBody()` does).
 * Regex/logic copied literally, byte-for-byte behavior, not rewritten.
 *
 * `addBasePath()` NOT ported: it rewrites/injects a `<base href>` tag
 * pointing at the landing's OWN url path (via `PageInfo::uri()`, which in
 * legacy is a real routed URL like `/local/<folder>/index.php` — legacy
 * serves local landings at a real, addressable path). traffic-core has no
 * path-based landing routing at all yet (`FindCampaignStage` only
 * resolves `?campaign=<alias>` or a domain default, see its docblock) —
 * there is no "local URL for this landing" to inject, so faking one would
 * produce a base href pointing nowhere real. Documented gap, not a silent
 * drop: relative resource paths inside a `local_file` landing page may
 * break without it, same honest tradeoff as `Meta`'s unported
 * `executeForScript()`.
 */
class HtmlPathAdapter
{
    public function adaptAnchors(string $content): string
    {
        $content = preg_replace_callback(
            '/\shref\s?=\s?["\']([^"^\']*?)#([^"^\']*?)["\']/',
            [$this, 'changeAnchors'],
            $content
        );

        return preg_replace_callback('/<a(.*?)[\s\'"]+>/', [$this, 'changeDoubleAttr'], $content);
    }

    public function adaptResourcePaths(string $content): string
    {
        $content = preg_replace_callback(
            '/src\s?=\s?["\']\/(.*?)["\']/',
            static function (array $m): string {
                if (str_starts_with($m[1], '/')) {
                    return $m[0];
                }

                return 'src="' . $m[1] . '"';
            },
            $content
        );

        return preg_replace_callback(
            '/<link ([^>]+)href\s?=\s?["\']\/(.*?)["\']/si',
            static function (array $m): string {
                if (str_starts_with($m[2], '/')) {
                    return $m[0];
                }

                return '<link ' . $m[1] . 'href="' . $m[2] . '"';
            },
            $content
        );
    }

    public function adaptFormAction(string $content, string $action = 'index.php'): string
    {
        return str_replace('action=""', 'action="' . $action . '"', $content);
    }

    private function changeAnchors(array $m): string
    {
        if (str_starts_with($m[1], '//') || str_starts_with($m[1], 'http://') || str_starts_with($m[1], 'https://')) {
            return $m[0];
        }

        return ' href="#' . $m[2] . '" onclick="document.location.hash=\'' . $m[2] . '\';return false;"';
    }

    private function changeDoubleAttr(array $m): string
    {
        $content = $m[0];
        $found = preg_match_all('/onclick\s?=\s?["\'](.*?)["\'][\s>]/', $content, $matches);

        if ($found > 1) {
            $onclick = [];
            while (--$found > 0) {
                $onclick[] = trim($matches[1][$found], ';');
                $content = str_replace(trim($matches[0][$found], '>'), '', $content);
            }
            $onclick[] = $matches[1][$found];
            $content = str_replace($matches[1][$found], implode(';', $onclick), $content);
        }

        return $content;
    }
}
