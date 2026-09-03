<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Port of legacy `Component\Conversions\PruneTask\PruneDailyCap`
 * (application/Component/Conversions/PruneTask/PruneDailyCap.php) ->
 * `Component\Conversions\ConversionCapacity\Storage\RedisStorage::
 * prune()` (application/Component/Conversions/ConversionCapacity/
 * Storage/RedisStorage.php) — for every `daily_cap:<offer_id>` sorted
 * set (written by `App\Services\ConversionCapacityService::store()`/
 * traffic-core's `TrafficCore\ConversionCapacity\
 * ConversionCapacityService::store()` on each new capped-offer
 * conversion), `ZREMRANGEBYSCORE` everything older than 2 days
 * (`TTL = 2`) - unlike `PruneHitLimits`, legacy's real `RedisStorage::
 * prune()` here has NO exception list at all (confirmed by reading the
 * source directly - `getStorages()`'s daily-cap storage has nothing
 * equivalent to hit-limit's "total cap" carve-out), so every
 * `daily_cap:*` key is pruned unconditionally.
 *
 * Was previously listed as blocked on the unported ConversionCapacity
 * module in docs/BACKEND_REMAINING_WORK.md section 2 - the module
 * itself was ported alongside this command (2026-09-03, "добей хвосты"
 * round): `offers.conversion_cap_enabled`/`daily_cap`/
 * `conversion_timezone`/`alternative_offer_id` columns had existed in
 * this port's schema all along, just with no runtime logic reading them
 * yet (see `TrafficCore\Pipeline\ChooseOfferStage`'s docblock for the
 * offer-selection/alternative-offer-chain half, and
 * `App\Services\ConversionCapacityService`/`TrafficCore\Postback\
 * PostbackProcessor::recordConversionCap()` for the write half).
 */
class PruneDailyCap extends Command
{
    private const SET_PREFIX = 'daily_cap:';

    private const TTL_DAYS = 2;

    protected $signature = 'app:prune-daily-cap';

    protected $description = 'Prune old entries from the daily_cap:<offer_id> conversion-cap Redis sorted sets';

    public function handle(): int
    {
        $redis = Redis::connection('traffic');
        $keys = $redis->keys(self::SET_PREFIX.'*');
        $until = now()->subDays(self::TTL_DAYS)->getTimestamp();

        foreach ($keys as $key) {
            $redis->zremrangebyscore($key, '-inf', $until);
        }

        $this->info('Pruned entries older than '.$until.' from '.count($keys).' daily-cap set(s).');

        return self::SUCCESS;
    }
}
