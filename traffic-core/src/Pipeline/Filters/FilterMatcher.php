<?php

namespace TrafficCore\Pipeline\Filters;

/**
 * Fixed port of legacy `Component\StreamFilters\Service\StreamFilterService`
 * (`findInWithRegexSupport()` / `equalOrEmpty()` / `getEmptyQueries()`).
 *
 * BUG FIX (not a faithful port): legacy does
 * `$string = (int) $string; $pattern = trim((int) $pattern);` before ANY
 * comparison — casting a referrer/user-agent/arbitrary-param STRING to an
 * integer before regex/wildcard/substring matching. Every non-numeric
 * value collapses to `0`, which silently defeats the wildcard (`*`) and
 * regex (`/.../flags`) branches immediately below it in the same
 * function — those branches can never fire because `$pattern` is already
 * a plain int by the time they run. This is almost certainly a
 * decompilation artifact (see docs/PORTING_LOG.md — this codebase is a
 * decompiled fork), not an intentional numeric-only filter design: it
 * would make Referrer/UserAgent/AnyParam/Parameter/Language non-functional
 * for the string values they exist to match. Ported here WITHOUT the
 * `(int)` casts; every other branch (empty-value sentinels, exact match,
 * regex, wildcard, case-insensitive strict/substring) is unchanged.
 */
class FilterMatcher
{
    private const EMPTY_QUERIES = ['@empty', 'Unknown', 'XX'];

    public static function findInWithRegexSupport(string $string, mixed $pattern, bool $isStrict = true): bool
    {
        $pattern = is_string($pattern) ? trim($pattern) : (string) $pattern;

        if (in_array($pattern, self::EMPTY_QUERIES, true) && $string === '') {
            return true;
        }

        if ($string === $pattern) {
            return true;
        }

        if ($pattern === '') {
            return false;
        }

        $regex = false;
        $regexPattern = $pattern;

        if (str_starts_with($pattern, '/') && preg_match('/^\/.+\/[imsxeADSUJXu]*$/', $pattern)) {
            $regex = true;
        } elseif (str_contains($pattern, '*')) {
            $regexPattern = self::clearForPreg($pattern);
            $regexPattern = '/^' . str_replace('*', '(.*?)', $regexPattern) . '$/uis';
            $regex = true;
        }

        if ($regex) {
            $match = @preg_match($regexPattern, $string);
            if ($match === false) {
                error_log("[FilterMatcher] Invalid regular expression {$regexPattern}");

                return false;
            }

            return (bool) $match;
        }

        if ($isStrict) {
            return strcasecmp($string, $pattern) === 0;
        }

        return stripos($string, $pattern) !== false;
    }

    public static function equalOrEmpty(string $string, mixed $search): bool
    {
        $search = is_string($search) ? trim($search) : (string) $search;

        if ($search === '@empty' && $string === '') {
            return true;
        }

        return mb_strtolower($search, 'UTF-8') === mb_strtolower($string, 'UTF-8');
    }

    private static function clearForPreg(string $string): string
    {
        $from = ['[', ']', '{', '}', '(', ')', '/', '.', ' ', '?', '-'];
        $to = ['\\[', '\\]', '\\{', '\\}', '\\(', '\\)', '\\/', '\\.', '\\s', '\\?', '\\-'];

        return str_replace($from, $to, $string);
    }
}
