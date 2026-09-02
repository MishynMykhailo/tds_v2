<?php

namespace TrafficCore\Redis;

use Predis\Client;

/**
 * Thin singleton wrapper/factory around `Predis\Client`, mirroring
 * `TrafficCore\Db`'s exact singleton style (private static ?instance +
 * public static instance()). Connects to the `tds2-redis` container on
 * the shared `deploy_default` docker network — same env-var-with-dev-
 * fallback pattern as `Db::instance()` (`getenv() ?: 'default'`).
 *
 * traffic-core had no Redis client at all before this — added
 * specifically for `TrafficCore\LpToken\LpTokenService::storeRawClick()`
 * (see that class's docblock), the server-side lookup-token store behind
 * `GenerateTokenStage`. Library choice: `predis/predis` (pure-PHP,
 * requires no `ext-redis`) — checked on Packagist before adding: v3.6.0
 * current stable, 341M+ total installs, 2,887 dependent packages, MIT
 * license, maintained by nrk/Till Krüss, 0 reported security advisories.
 * Clean; added directly per this project's package-vetting rule (report
 * findings, then act — no need to ask when the check is clean).
 */
class RedisClient
{
    private static ?Client $instance = null;

    public static function instance(): Client
    {
        if (self::$instance === null) {
            $host = getenv('REDIS_HOST') ?: 'tds2-redis';
            $port = getenv('REDIS_PORT') ?: '6379';

            self::$instance = new Client([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => (int) $port,
            ]);
        }

        return self::$instance;
    }
}
