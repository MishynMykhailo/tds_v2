<?php

namespace App\Console\Commands;

use App\Models\AffiliateNetwork;
use App\Models\Campaign;
use App\Models\Domain;
use App\Models\Landing;
use App\Models\Offer;
use App\Models\Setting;
use App\Models\Stream;
use App\Models\TrafficSource;
use Illuminate\Console\Command;

/**
 * Port of legacy `Component\Domains\CronTask\PruneArchive`... actually
 * `Cron\PruneArchive` (application/Component/Archive/CronTask/
 * PruneArchive.php), which runs every `PruneTaskRepository::ARCHIVE_TYPE`
 * task — one per entity, each a thin `BaseArchivePruneTask` +
 * `Pruner` wrapper (application/Component/PruneTask/Pruner.php):
 * `campaigns`/`streams`/`offers`/`landings`/`traffic_sources`/
 * `affiliate_networks`/`domains` (confirmed exhaustive via
 * `grep -rl "extends.*BaseArchivePruneTask" application/`).
 *
 * Mechanism ported 1-to-1 from `Pruner::prune()`/`pruneBefore()`: hard-
 * delete rows with `state = 'deleted'` AND `updated_at` older than the
 * `archive_ttl` setting (in days). `archive_ttl` empty/0/missing means
 * "cleanup disabled" (`Pruner::isCleanDisabled()`), matching legacy's own
 * safe default — a fresh install with the setting never touched prunes
 * nothing.
 *
 * NOT ported: legacy's per-task `deleteAll()` (`pruneAll()` — no TTL
 * check, wipes every deleted row regardless of age) — only reachable via
 * a legacy admin "empty trash now" action this Laravel port has no
 * equivalent UI surface for yet.
 */
class PruneArchivedEntities extends Command
{
    protected $signature = 'app:prune-archived-entities';

    protected $description = 'Hard-delete soft-deleted (state=deleted) campaigns/streams/offers/landings/traffic sources/affiliate networks/domains older than the archive_ttl setting (in days)';

    /** @var array<int, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const MODELS = [
        Campaign::class,
        Stream::class,
        Offer::class,
        Landing::class,
        TrafficSource::class,
        AffiliateNetwork::class,
        Domain::class,
    ];

    public function handle(): int
    {
        $ttlDays = (int) (Setting::query()->find('archive_ttl')?->value ?? 0);

        if ($ttlDays <= 0) {
            $this->info('archive_ttl not set (or 0) — archive pruning disabled, nothing to do.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($ttlDays);

        foreach (self::MODELS as $model) {
            $deleted = $model::where('state', 'deleted')
                ->where('updated_at', '<', $cutoff)
                ->delete();

            if ($deleted > 0) {
                $this->info("{$model}: pruned {$deleted} archived row(s) older than {$ttlDays} day(s).");
            }
        }

        return self::SUCCESS;
    }
}
