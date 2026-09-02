<?php

namespace TrafficCore\Macros;

/**
 * Port of legacy `Traffic\Macros\MacrosProcessor` +
 * `Traffic\Macros\ParserItem` (application/Traffic/Macros/
 * MacrosProcessor.php, application/Traffic/Macros/ParserItem.php) — the
 * `{macro_name:arg1,arg2}` / `{_macro_name}` / `$macro_name` /
 * `$_macro_name` placeholder substitution engine used across action
 * content/URLs and (via `TrafficCore\Postback\OutboundPostbackService`)
 * outbound S2S postback URLs.
 *
 * Architectural split from legacy, not a fidelity cut: legacy's
 * `MacrosProcessor::processContent()` takes a `Core\Sandbox\
 * SandboxContext` and looks macros up in a live `MacroRepository` of ~30
 * registered macro OBJECTS (`Traffic\Macros\Predefined\*`), each reading
 * whatever click/conversion/stream data it needs directly. This port
 * decouples the PARSE+SUBSTITUTE engine (this class — data-source
 * agnostic) from macro RESOLUTION (the caller passes in an already
 * -resolved `array<string,?string>` of macro name => value): click-context
 * callers build theirs via `ClickMacroValues::forPayload()`, the
 * postback's outbound-URL caller builds a much smaller conversion
 * -context one inline. Same net behavior (a registered macro name wins
 * over an identically-named request param; args after `:` are passed to
 * the macro but this port's macros are simple enough that all args
 * beyond presence are ignored — see `ClickMacroValues`'s docblock for
 * exactly which legacy macro arguments are and aren't honored).
 *
 * `_addParamsFromCampaign()` (campaign-configured extra macro sources
 * via `campaigns.parameters`) is NOT ported here — `CheckParamAliasesStage`
 * already resolves `campaigns.parameters` aliases into
 * `payload->resolvedParams` earlier in the click pipeline, and
 * `ClickMacroValues::forPayload()` reads through that same resolved
 * view, so the practical effect (an aliased campaign param being
 * substitutable) is preserved without a second, separate mechanism.
 */
class MacrosProcessor
{
    /**
     * @param array<string,string|null> $macros Registered macro name =>
     *        resolved value (null means "macro exists but has nothing to
     *        say for this request" — legacy's behavior for that case:
     *        leave the placeholder text as-is, forced to raw/un-encoded).
     * @param array<string,mixed> $params Fallback source (request query/
     *        body params) consulted only for names NOT in `$macros` at
     *        all — legacy's `_searchInParams()`.
     */
    public static function process(string $content, array $macros, array $params = []): string
    {
        if (!str_contains($content, '$') && !str_contains($content, '{')) {
            return $content;
        }

        foreach (self::parse($content) as $item) {
            if (array_key_exists($item['name'], $macros)) {
                $value = $macros[$item['name']];
                if ($value === null) {
                    // Registered macro returned nothing — legacy forces
                    // raw mode and re-inserts the untouched placeholder
                    // text (a no-op replace, but matches the documented
                    // behavior exactly rather than silently skipping).
                    continue;
                }
                $content = self::replace($content, $item, $value);

                continue;
            }

            if (array_key_exists($item['name'], $params)) {
                $value = $params[$item['name']];
                $value = is_array($value) ? (string) json_encode($value) : (string) $value;
                $content = self::replace($content, $item, $value);
            }
        }

        return $content;
    }

    /**
     * @return list<array{name:string,original:string,raw:bool,args:list<string>}>
     */
    private static function parse(string $content): array
    {
        $patterns = ['/{(_?)([a-z0-9_\-]+):?([^{^}]*?)}/i', '/\$(_?)([a-z0-9_-]+)/i'];
        $items = [];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $content, $matches)) {
                foreach ($matches[0] as $n => $original) {
                    $items[] = [
                        'name' => $matches[2][$n],
                        'original' => $original,
                        'raw' => $matches[1][$n] === '_',
                        'args' => isset($matches[3][$n]) && $matches[3][$n] !== ''
                            ? explode(',', $matches[3][$n])
                            : [],
                    ];
                }
            }
        }

        return $items;
    }

    /** @param array{name:string,original:string,raw:bool,args:list<string>} $item */
    private static function replace(string $content, array $item, string $value): string
    {
        if (!$item['raw']) {
            $value = urlencode($value);
        }

        return str_replace($item['original'], $value, $content);
    }
}
