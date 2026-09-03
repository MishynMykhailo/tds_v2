<?php

namespace App\Console\Commands;

use App\Models\StreamFilter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Port of legacy `Component\StreamFilters\PruneTask\PruneHitLimits`
 * (application/Component/StreamFilters/PruneTask/PruneHitLimits.php) ->
 * `Traffic\HitLimit\Storage\RedisStorage::prune()` (application/Traffic/
 * HitLimit/Storage/RedisStorage.php) — for every `rate:<stream_id>`
 * sorted set (written by traffic-core's `TrafficCore\HitLimit\
 * HitLimitService::store()` on each hit-limit-filtered click),
 * `ZREMRANGEBYSCORE` everything older than 1 day (`TTL = 1`), EXCEPT
 * streams whose `limit` StreamFilter has a `total` cap set — those need
 * their full history forever since `total` counts all-time hits, not
 * per-hour/per-day. Ported literally, including the same exception
 * query (`stream_filters` rows named "limit" with `payload.total`
 * truthy) and the same 1-day TTL.
 *
 * Was previously listed as blocked on unported infra in
 * docs/BACKEND_REMAINING_WORK.md section 2 - re-checked and that was
 * stale: the `rate:<stream_id>` mechanism has been real since
 * traffic-core Phase 11 (hit-limit/cost/payout), it just never had a
 * pruner. Uses the `traffic` Redis connection (config/database.php) —
 * an unprefixed connection into the SAME Redis instance/DB as
 * traffic-core's own `TrafficCore\Redis\RedisClient`, since Laravel's
 * `default`/`cache` connections apply their own key prefix and would
 * never see traffic-core's raw keys.
 */
class PruneHitLimits extends Command
{
    private const SET_PREFIX = 'rate:';

    private const TTL_DAYS = 1;

    protected $signature = 'app:prune-hit-limits';

    protected $description = 'Prune old entries from the rate:<stream_id> hit-limit Redis sorted sets traffic-core writes';

    public function handle(): int
    {
        $exceptions = StreamFilter::where('name', 'limit')
            ->get()
            ->filter(function (StreamFilter $filter): bool {
                $payload = is_string($filter->payload) ? json_decode($filter->payload, true) : $filter->payload;

                return is_array($payload) && ! empty($payload['total']);
            })
            ->pluck('stream_id')
            ->all();

        $redis = Redis::connection('traffic');
        $keys = $redis->keys(self::SET_PREFIX.'*');
        $until = now()->subDays(self::TTL_DAYS)->getTimestamp();

        $pruned = 0;
        foreach ($keys as $key) {
            $streamId = (int) str_replace(self::SET_PREFIX, '', $key);

            if (in_array($streamId, $exceptions, true)) {
                continue;
            }

            $redis->zremrangebyscore($key, '-inf', $until);
            $pruned++;
        }

        $this->info("Pruned entries older than {$until} from {$pruned} hit-limit set(s), skipped ".count($exceptions).' exception(s) with a total cap.');

        return self::SUCCESS;
    }
}
