<?php

namespace TrafficCore\Pipeline\Filters;

/**
 * Ported subset of legacy `Component\StreamFilters\Filter\*` classes
 * (application/Component/StreamFilters/Filter/) — see
 * docs/TRAFFIC_CORE_PLAN.md "Фаза 4" for the full list of what's ported
 * vs. deferred and why.
 *
 * `evaluate()` returns `null` for a filter `name` that isn't implemented
 * here (caller — `CheckFilters` — treats that as fail-open for that ONE
 * filter, not the whole stream) and `true`/`false` for implemented ones.
 *
 * $signal shape (see Signal::fromRequest()): ip, referer, userAgent,
 * language, params (array), datetime (\DateTimeImmutable, UTC).
 */
class FilterEngine
{
    /** @param array<string,mixed>|scalar|null $payload */
    public static function evaluate(string $name, string $mode, mixed $payload, array $signal): ?bool
    {
        if (self::isAnyParamName($name)) {
            return self::anyParam($name, $mode, $payload, $signal);
        }

        return match ($name) {
            'parameter' => self::parameter($mode, $payload, $signal),
            'referrer' => self::referrer($mode, $payload, $signal),
            'empty_referrer' => self::emptyReferrer($mode, $signal),
            'schedule' => self::schedule($mode, $payload, $signal),
            'interval' => self::interval($mode, $payload, $signal),
            'ip' => self::ip($mode, $payload, $signal),
            'ipv_6' => self::ipv6($mode, $signal),
            'user_agent' => self::userAgent($mode, $payload, $signal),
            'language' => self::language($mode, $payload, $signal),
            default => null,
        };
    }

    private static function isAnyParamName(string $name): bool
    {
        return in_array($name, [
            'source', 'x_requested_with', 'keyword', 'search_engine', 'ad_campaign_id', 'creative_id',
        ], true) || (bool) preg_match('/^(sub_id_\d+|extra_param_\d+)$/', $name);
    }

    /**
     * Port of `Filter\AnyParam::isPass()`. Matches a single request
     * param (the filter's own `name`, e.g. "source", "sub_id_3") against
     * the payload's value list. NOTE (legacy quirk, not fixed — out of
     * the two confirmed-bug scope given for this phase): if the loop
     * finds no match, legacy's `isPass()` implicitly returns `NULL`
     * (falsy) regardless of ACCEPT/REJECT mode, i.e. "no match" always
     * behaves like "blocked" even in REJECT mode. Ported faithfully.
     */
    private static function anyParam(string $paramName, string $mode, mixed $payload, array $signal): bool
    {
        $value = (string) ($signal['params'][$paramName] ?? '');
        $values = is_array($payload) ? $payload : [$payload];

        foreach ($values as $filterValue) {
            if (FilterMatcher::findInWithRegexSupport($value, $filterValue, true)) {
                $found = true;

                return !($mode === 'accept' && ! $found || $mode === 'reject' && $found);
            }
        }

        return false; // no match found — legacy quirk, see docblock.
    }

    /** Port of `Filter\Parameter::isPass()` — proper full-loop boolean, no early-return quirk. */
    private static function parameter(string $mode, mixed $payload, array $signal): bool
    {
        $paramName = is_array($payload) ? ($payload['name'] ?? null) : null;
        $value = $paramName !== null ? (string) ($signal['params'][$paramName] ?? '') : '';
        $candidates = is_array($payload) && is_array($payload['value'] ?? null) ? $payload['value'] : [];

        $found = false;
        foreach ($candidates as $row) {
            if (FilterMatcher::findInWithRegexSupport($value, $row, true)) {
                $found = true;
            }
        }

        return !($mode === 'accept' && ! $found || $mode === 'reject' && $found);
    }

    /** Port of `Filter\Referrer::isPass()` — same early-return-on-no-match quirk as AnyParam. */
    private static function referrer(string $mode, mixed $payload, array $signal): bool
    {
        if (! is_array($payload)) {
            return true; // legacy: `if (!is_array($values)) return true;`
        }

        $referer = (string) ($signal['referer'] ?? '');

        foreach ($payload as $row) {
            if (FilterMatcher::findInWithRegexSupport($referer, $row, false)) {
                $found = true;

                return !($mode === 'accept' && ! $found || $mode === 'reject' && $found);
            }
        }

        return false;
    }

    private static function emptyReferrer(string $mode, array $signal): bool
    {
        $referer = (string) ($signal['referer'] ?? '');

        return ($mode === 'accept' && $referer === '') || ($mode === 'reject' && $referer !== '');
    }

    /** Port of `Filter\UserAgent::isPass()` — same early-return-on-no-match quirk as AnyParam. */
    private static function userAgent(string $mode, mixed $payload, array $signal): bool
    {
        if (! is_array($payload)) {
            $payload = [];
        }

        $ua = (string) ($signal['userAgent'] ?? '');

        foreach ($payload as $row) {
            if (FilterMatcher::findInWithRegexSupport($ua, $row, false)) {
                $found = true;

                return !($mode === 'accept' && ! $found || $mode === 'reject' && $found);
            }
        }

        return false;
    }

    /** Port of `Filter\Language::isPass()` — same early-return-on-no-match quirk as AnyParam. */
    private static function language(string $mode, mixed $payload, array $signal): bool
    {
        if (! is_array($payload)) {
            $payload = [];
        }

        $language = (string) ($signal['language'] ?? '');

        foreach ($payload as $row) {
            if (FilterMatcher::equalOrEmpty($language, $row)) {
                $found = true;

                return !($mode === 'accept' && ! $found || $mode === 'reject' && $found);
            }
        }

        return false;
    }

    /**
     * Port of `Filter\Ip::isPass()`.
     *
     * BUG FIX (found while porting, not one of the two flagged up front):
     * legacy's token loop (`strtok($string, ",;")` ... `while ($tok !==
     * false)`) only calls `strtok()` again in the `else` branch — when a
     * token MATCHES, `$tok` is never reassigned, so the `while` loop spins
     * forever on that same token (infinite loop / CPU DoS the moment any
     * IP filter value matches). Ported here with a plain `preg_split()`
     * token list instead of `strtok()`, iterating every token exactly
     * once regardless of match — same matching semantics, no infinite
     * loop.
     */
    private static function ip(string $mode, mixed $payload, array $signal): bool
    {
        if (! is_array($payload)) {
            $payload = [];
        }

        $ip = (string) ($signal['ip'] ?? '');
        $found = false;

        foreach ($payload as $string) {
            foreach (preg_split('/[,;]/', (string) $string, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
                if (self::ipTokenMatches(trim($token), $ip)) {
                    $found = true;
                }
            }
        }

        return ($mode === 'accept' && $found) || ($mode === 'reject' && ! $found);
    }

    private static function ipTokenMatches(string $mask, string $ip): bool
    {
        if ($mask === '' || $ip === '') {
            return false;
        }

        if (str_contains($mask, '/')) {
            return IpMatcher::ipInCidr($ip, $mask);
        }

        if (substr_count($mask, '.') === 6) {
            return IpMatcher::ipInInterval($ip, $mask);
        }

        return IpMatcher::ipInMask($ip, $mask);
    }

    private static function ipv6(string $mode, array $signal): bool
    {
        $ip = (string) ($signal['ip'] ?? '');
        $isV6 = $ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;

        return ($mode === 'accept' && $isV6) || ($mode === 'reject' && ! $isV6);
    }

    /** Port of `Filter\Schedule::isPass()` (day-of-week + time-of-day intervals, optional timezone). */
    private static function schedule(string $mode, mixed $payload, array $signal): bool
    {
        $payload = is_array($payload) ? $payload : [];
        $prepared = array_key_exists('intervals', $payload) ? $payload : ['intervals' => $payload, 'timezone' => null];

        $now = $signal['datetime'];
        $result = false;

        foreach ((array) ($prepared['intervals'] ?? []) as $daySchedule) {
            if (self::isThatDayAndTime($now, $daySchedule, $prepared['timezone'] ?? null)) {
                $result = true;
            }
        }

        return ($mode === 'accept' && $result) || ($mode === 'reject' && ! $result);
    }

    private static function isThatDayAndTime(\DateTimeImmutable $time, array $daySchedule, ?string $timezone): bool
    {
        if (! empty($timezone)) {
            $time = $time->setTimezone(new \DateTimeZone($timezone));
        }

        $currentDay = ((int) $time->format('w') - 1 + 7) % 7; // legacy: Mon=0..Sun=6
        $currentTime = $time->format('H:i');

        $day = $daySchedule['day'] ?? [0, 6];
        if (! is_array($day)) {
            $day = [$day, $day];
        }
        [$dayFrom, $dayTo] = [$day[0], $day[1]];

        if ($dayFrom <= $dayTo) {
            if ($currentDay < $dayFrom || $dayTo < $currentDay) {
                return false;
            }
            if ($dayFrom < $currentDay && $currentDay < $dayTo) {
                return true;
            }
        } else {
            if ($dayTo < $currentDay && $currentDay < $dayFrom) {
                return false;
            }
            if ($currentDay < $dayTo || $dayFrom < $currentDay) {
                return true;
            }
        }

        $time_ = $daySchedule['time'] ?? null;
        if (empty($time_)) {
            return true;
        }

        if ($dayFrom <= $dayTo) {
            return ($currentDay !== $dayFrom || $time_[0] <= $currentTime)
                && ($currentDay !== $dayTo || $currentTime <= $time_[1]);
        }

        return ($currentDay !== $dayTo || $currentTime <= $time_[1])
            && ($currentDay !== $dayFrom || $time_[0] <= $currentTime);
    }

    /** Port of `Filter\Interval::isPass()` (plain calendar-date range, optional timezone). */
    private static function interval(string $mode, mixed $payload, array $signal): bool
    {
        $payload = is_array($payload) ? $payload : [];
        $tz = ! empty($payload['timezone']) ? new \DateTimeZone($payload['timezone']) : null;
        $now = $tz !== null ? $signal['datetime']->setTimezone($tz) : $signal['datetime'];

        $include = true;

        if (! empty($payload['from'])) {
            $from = new \DateTimeImmutable(explode('T', $payload['from'])[0], $tz);
            $from = $from->setTime(0, 0, 0);
            $include = $from <= $now;
        }

        if (! empty($payload['to']) && $include) {
            $to = new \DateTimeImmutable(explode('T', $payload['to'])[0], $tz);
            $to = $to->setTime(0, 0, 0);
            $include = $now <= $to;
        }

        return ($mode === 'accept' && $include) || ($mode === 'reject' && ! $include);
    }
}
