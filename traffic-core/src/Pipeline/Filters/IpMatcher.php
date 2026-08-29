<?php

namespace TrafficCore\Pipeline\Filters;

/**
 * Fixed/ported subset of legacy `Traffic\Tools\Tools` IP-matching helpers
 * (`ipInCIDR()`, `ipInMask()`, `ipInInterval()`) used by the `Ip` stream
 * filter.
 *
 * BUG FIX — `ipInCIDR()`: legacy concatenates the IP's octets into one
 * string and truncates it by CHARACTER COUNT using `$mask` (the CIDR
 * prefix length) as if it were a string-length, not a bit count
 * (`substr(join("", $ipc), 0, $mask)`), and also references an undefined
 * variable `$v` (`str_pad(decbin($v), ...)` with `$v` never assigned —
 * dead code / PHP notice). This only happens to work when the prefix
 * lands on an octet boundary (/8, /16, /24) and gives wrong matches for
 * anything else (/12, /20, /27, ...). Ported here as correct bitwise
 * IPv4 CIDR matching. IPv6 CIDR is out of scope (legacy doesn't support
 * it here either — `Ip` filter's `_checkIp()` only ever calls `ipInCIDR`
 * for IPv4-shaped masks).
 */
class IpMatcher
{
    public static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLen] = explode('/', $cidr, 2);
        $prefixLen = (int) $prefixLen;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false || $prefixLen < 0 || $prefixLen > 32) {
            return false;
        }

        if ($prefixLen === 0) {
            return true;
        }

        $mask = -1 << (32 - $prefixLen);

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    /**
     * Ported as-is from legacy `Tools::ipInInterval()` — a plain numeric
     * range check (`a.b.c.d-a.b.c.d`), no bug found here.
     */
    public static function ipInInterval(string $ip, string $interval): bool
    {
        [$from, $to] = explode('-', $interval, 2);

        return self::ip2long($ip) >= self::ip2long(trim($from))
            && self::ip2long($ip) <= self::ip2long(trim($to));
    }

    /**
     * Ported as-is from legacy `Tools::ipInMask()` — wildcard octet mask
     * matching (`x`/`?` per-octet wildcards, e.g. `192.168.x.x`), no bug
     * found here (only `ipInCIDR` was broken).
     */
    public static function ipInMask(string $ip, string $mask): bool
    {
        $octets = explode('.', $ip);
        if (count($octets) !== 4) {
            return false;
        }

        $numericIp = '1' . sprintf('%03d', (int) $octets[0]) . sprintf('%03d', (int) $octets[1])
            . sprintf('%03d', (int) $octets[2]) . sprintf('%03d', (int) $octets[3]);

        $line = trim($mask);
        $line = str_replace('x', '*', $line);
        $line = preg_replace('/[A-Za-z#\/]/', '', $line);

        $max = $line;
        $min = $line;

        if (str_contains($line, '*')) {
            $max = str_replace('*', '999', $line);
            $min = str_replace('*', '000', $line);
        }

        if (str_contains($line, '?')) {
            $max = str_replace('?', '9', $max);
            $min = str_replace('?', '0', $min);
        }

        if ($max === '') {
            return false;
        }

        $maxNumeric = self::buildBound($max, useUpperOfRange: true);
        $minNumeric = self::buildBound($min, useUpperOfRange: false);

        $ipInt = (int) $numericIp;

        return $ipInt <= (int) $maxNumeric && (int) $minNumeric <= $ipInt;
    }

    private static function buildBound(string $value, bool $useUpperOfRange): string
    {
        $octets = explode('.', $value);
        $result = '1';

        for ($i = 0; $i < 4; $i++) {
            $octet = $octets[$i] ?? '0';
            if (str_contains($octet, '-')) {
                $range = explode('-', $octet, 2);
                $octet = $useUpperOfRange ? ($range[1] ?? $range[0]) : $range[0];
            }
            $result .= sprintf('%03d', (int) $octet);
        }

        return $result;
    }

    private static function ip2long(string $ip): float
    {
        $long = ip2long($ip);

        return $long === false ? 0.0 : (float) sprintf('%u', $long);
    }
}
