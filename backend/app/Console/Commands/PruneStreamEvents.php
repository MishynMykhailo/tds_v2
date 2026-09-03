<?php

namespace App\Console\Commands;

use App\Models\StreamEvent;
use Illuminate\Console\Command;

/**
 * Port of legacy `Component\Streams\PruneTask\PruneStreamEvents`
 * (application/Component/Streams/PruneTask/PruneStreamEvents.php) ->
 * `StreamEventService::prune()` (application/Component/Streams/Service/
 * StreamEventService.php) — a plain `DELETE FROM monitoring_history
 * WHERE date < now() - 30 days`, verified byte-for-byte against the
 * real legacy source, not guessed (`PRUNE_PERIOD = 30`).
 *
 * Was previously listed as blocked on unported infra in
 * docs/BACKEND_REMAINING_WORK.md section 2 — re-checked and that was
 * wrong: `monitoring_history` (this port's `App\Models\StreamEvent`) has
 * been a real, fully-populated table since the StreamEvents module was
 * ported early in this project. No dependency was ever missing here.
 */
class PruneStreamEvents extends Command
{
    private const PRUNE_PERIOD_DAYS = 30;

    protected $signature = 'app:prune-stream-events';

    protected $description = 'Delete monitoring_history (stream event) rows older than 30 days';

    public function handle(): int
    {
        $cutoff = now()->subDays(self::PRUNE_PERIOD_DAYS);

        $deleted = StreamEvent::where('date', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} stream event row(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
