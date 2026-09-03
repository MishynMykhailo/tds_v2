<?php

namespace App\Console\Commands;

use App\Models\ClickLink;
use App\Models\Conversion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Port of `Component\Cleaner\Service\CleanerService::pruneVisitors()` /
 * `pruneConversions()` / `pruneClickLinks()` (application/Component/
 * Cleaner/Service/CleanerService.php), invoked in legacy by
 * `Grid\CronTask\PruneReferences` -> `Cleaner\DelayedCommand\
 * PruneReferencesCommand` -> every `PruneTaskRepository::REFERENCE_TYPE`
 * task (`Clicks\PruneTask\PruneVisitors`, `Conversions\PruneTask\
 * PruneConversions`, `Clicks\PruneTask\PruneReferences` — the last of
 * which bundles `pruneReferences()` AND `pruneClickLinks()`, see that
 * class).
 *
 * Cleans up rows left orphaned after clicks are pruned (by
 * `PruneClickStats`/`PruneArchivedEntities` or the legacy equivalent):
 *  - `visitors` with no click referencing it any more.
 *  - `conversions` whose `click_id` no longer exists in `clicks`.
 *  - `click_links` whose `sub_id` no longer exists in `clicks`.
 *
 * NOT ported: `pruneReferences()` itself (the `ref_*` dictionary-table
 * cleanup) — legacy derives its list of dictionary tables/FK columns
 * from `Component\Clicks\Grid\ClicksDefinition::getRelations()`, which
 * isn't ported (see `ConversionsController::updateCostDefinitionAction`'s
 * docblock for the same dependency). A hand-rolled version against this
 * project's known `ref_*` tables (see `App\Services\Grid\
 * EntityGridBuilder`/`DictionaryRepository`) is a reasonable follow-up,
 * not attempted here to avoid silently missing a table and leaving stale
 * rows forever.
 *
 * No `visitors` Eloquent model exists in `backend/` (that table is a
 * `traffic-core/`-only concern, managed via raw PDO there — see
 * `traffic-core/src/Pipeline/Visitor/VisitorResolver.php`) — queried here
 * via the query builder instead, same table/columns.
 */
class PruneOrphanedData extends Command
{
    protected $signature = 'app:prune-orphaned-data';

    protected $description = 'Delete visitors/conversions/click_links rows left orphaned after their referencing clicks were pruned';

    public function handle(): int
    {
        $visitors = DB::table('visitors')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('clicks')
                    ->whereColumn('clicks.visitor_id', 'visitors.id');
            })
            ->delete();

        $conversions = Conversion::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('clicks')
                    ->whereColumn('clicks.click_id', 'conversions.click_id');
            })
            ->delete();

        $clickLinks = ClickLink::query()
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('clicks')
                    ->whereColumn('clicks.sub_id', 'click_links.sub_id');
            })
            ->delete();

        $this->info("Pruned {$visitors} orphaned visitor(s), {$conversions} orphaned conversion(s), {$clickLinks} orphaned click_link(s).");

        return self::SUCCESS;
    }
}
