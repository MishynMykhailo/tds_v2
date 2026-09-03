<?php

namespace App\Console\Commands;

use App\Jobs\DeleteStatsJob;
use App\Models\Setting;
use Illuminate\Console\Command;

/**
 * Port of legacy `Component\Clicks\CronTask\PruneClicks`
 * (application/Component/Clicks/CronTask/PruneClicks.php) ->
 * `CleanerService::pruneClicks($ttlInDays)`.
 *
 * Reuses the already-ported `App\Jobs\DeleteStatsJob` (itself a port of
 * `Cleaner\DelayedCommand\DeleteStatsCommand` — the manual
 * `?object=cleaner.clean` admin action) instead of re-implementing the
 * same click+conversion delete: legacy's `pruneClicks()` is really just
 * `deleteStats()`/`_cleanData()` with an implicit date range of "anything
 * older than N days" and no campaign scoping — exactly what dispatching
 * `DeleteStatsJob` with `endDate = now() - N days` and `campaignId =
 * null` already does.
 *
 * `stats_ttl` empty/0/missing means "cleanup disabled" — literal port of
 * legacy's own guard (`if (empty($statsTTL) || $statsTTL == 0) return
 * NULL;`), same safe-by-default behavior as `PruneArchivedEntities`'s
 * `archive_ttl`.
 *
 * NOT ported: legacy's `areStatTablesLocked()` guard in `isReady()` — a
 * MyISAM-era whole-table-lock concern (this project's InnoDB `clicks`
 * table doesn't take exclusive locks for a bulk DELETE the way legacy's
 * storage engine could), and the `DeleteStatsJob` dispatch here already
 * runs on the queue (not synchronously in the scheduler process), so a
 * long-running prune never blocks the scheduler tick either way.
 */
class PruneClickStats extends Command
{
    protected $signature = 'app:prune-click-stats';

    protected $description = 'Dispatch DeleteStatsJob to delete clicks/conversions older than the stats_ttl setting (in days)';

    public function handle(): int
    {
        $ttlDays = (int) (Setting::query()->find('stats_ttl')?->value ?? 0);

        if ($ttlDays <= 0) {
            $this->info('stats_ttl not set (or 0) — click/conversion pruning disabled, nothing to do.');

            return self::SUCCESS;
        }

        $endDate = now()->subDays($ttlDays)->format('Y-m-d H:i:s');

        DeleteStatsJob::dispatch(null, $endDate, null, null);

        $this->info("Dispatched DeleteStatsJob for clicks/conversions older than {$ttlDays} day(s) (before {$endDate}).");

        return self::SUCCESS;
    }
}
