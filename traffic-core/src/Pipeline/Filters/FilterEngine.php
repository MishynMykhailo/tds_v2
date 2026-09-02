<?php

namespace TrafficCore\Pipeline\Filters;

use TrafficCore\HitLimit\HitLimitService;

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
 *
 * `$streamId` (added for the `limit` filter type — see `limit()`'s
 * docblock): the id of the stream the filter row being evaluated belongs
 * to. Threaded through as a plain trailing parameter rather than, say,
 * bundling it into `$signal` (which is per-REQUEST, not per-stream —
 * `StreamRotator` builds one `Signal` and reuses it across every
 * candidate stream it tries, so stuffing a stream id into it would mean
 * mutating/rebuilding it per filter row for no benefit) or adding a
 * `Payload`/stream-object parameter (this class takes no `Payload` at
 * all today, by design — it's a pure function of "filter row + signal";
 * a raw `int` keeps that). `CheckFilters::isPass()` already has the full
 * stream row in scope at its one call site, so this is a one-line change
 * there (`(int) $stream['id']`) — no other call site exists
 * (confirmed via `grep -rn "FilterEngine::evaluate"`).
 */
class FilterEngine
{
    /** @param array<string,mixed>|scalar|null $payload */
    public static function evaluate(string $name, string $mode, mixed $payload, array $signal, int $streamId): ?bool
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
            'limit' => self::limit($mode, $payload, $streamId, $signal),
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

    /**
     * Port of `Filter\Limit::isPass()` (application/Component/
     * StreamFilters/Filter/Limit.php) — real hit-limit enforcement,
     * backed by `TrafficCore\HitLimit\HitLimitService` (Redis sorted set
     * per stream). Legacy's `getModes()` returns `NULL` for this filter
     * type (no accept/reject payload shape) — `$mode` is accepted here
     * only for signature parity with every other private method in this
     * class and is unused, exactly like legacy's `isPass()` ignores it.
     *
     * Ordering (ported faithfully, not a bug here): this check runs
     * BEFORE the current click's own hit is recorded —
     * `UpdateHitLimitStage::process()` (which calls `HitLimitService::
     * store()`) runs later in the pipeline, after this filter has already
     * decided pass/fail. So e.g. a `{"total":2}` limit blocks the 3rd
     * click (the count read here was already 2 when checked), never the
     * 2nd — the click being evaluated is never counted against itself.
     */
    private static function limit(string $mode, mixed $payload, int $streamId, array $signal): bool
    {
        $payload = is_array($payload) ? $payload : [];
        $timestamp = $signal['datetime']->getTimestamp();
        $service = new HitLimitService();

        $limitExceeded = false;

        if (isset($payload['per_hour']) && is_numeric($payload['per_hour'])
            && $payload['per_hour'] <= $service->perHour($streamId, $timestamp)) {
            $limitExceeded = true;
        }

        if (!$limitExceeded && isset($payload['per_day']) && is_numeric($payload['per_day'])
            && $payload['per_day'] <= $service->perDay($streamId, $timestamp)) {
            $limitExceeded = true;
        }

        if (!$limitExceeded && isset($payload['total']) && is_numeric($payload['total'])
            && $payload['total'] <= $service->total($streamId)) {
            $limitExceeded = true;
        }

        if (!$limitExceeded) {
            $limitExceeded = self::limitCheckNotSetValue($payload);
        }

        return !$limitExceeded;
    }

    /**
     * Port of `Filter\Limit::_checkNotSetValue()` — literal, including
     * the odd real behavior it names: if a filter payload has all three
     * keys (`per_hour`/`per_day`/`total`) PRESENT but ALL empty, the
     * filter blocks every click. This is legacy's actual behavior for a
     * misconfigured/emptied-out limit filter, not "fixed" here.
     *
     * @param array<string,mixed> $payload
     */
    private static function limitCheckNotSetValue(array $payload): bool
    {
        return array_key_exists('per_hour', $payload)
            && array_key_exists('per_day', $payload)
            && array_key_exists('total', $payload)
            && empty($payload['per_hour'])
            && empty($payload['per_day'])
            && empty($payload['total']);
    }
}
