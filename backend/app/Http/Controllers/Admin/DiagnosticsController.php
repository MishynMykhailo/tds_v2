<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Port of legacy `Component\Diagnostics\Controller\DiagnosticsController` +
 * `DiagnosticService::getStatus()` (object=diagnostics) — a self-health
 * report shown in the admin panel.
 *
 * DELIBERATELY SIMPLIFIED, not a 1:1 port (documented in
 * docs/PORTING_LOG.md): legacy's checks are a mix of things that map
 * cleanly onto this port (migrations applied, storage dir writable, free
 * disk space) and things tied to concepts that don't exist here at all —
 * the legacy custom migration runner, the legacy Cron-table heartbeat (see
 * PORTING_LOG re: Console/Cron/DelayedCommands using native Laravel
 * Scheduler/Queue instead, which has no equivalent "last run" table to
 * query the same way), and outbound network calls to the original vendor's
 * update/config-check servers (`tds.io`) — there's no vendor update service
 * for this product to check against anymore. Those are omitted rather than
 * faked.
 */
class DiagnosticsController extends Controller
{
    public function indexAction(Request $request): array
    {
        $problems = [];

        if (! $this->migrationsOk()) {
            $problems[] = ['id' => 'migrations', 'level' => 'critical'];
        }
        if (! is_writable(storage_path())) {
            $problems[] = ['id' => 'storage_dir', 'level' => 'info'];
        }
        if (! $this->hasFreeSpace()) {
            $problems[] = ['id' => 'no_free_space', 'level' => 'critical', 'free_space' => disk_free_space(storage_path())];
        }

        return [
            'ok' => empty($problems),
            'problems' => $problems,
        ];
    }

    /** Have all migration files on disk actually been run? */
    private function migrationsOk(): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('migrations')) {
            return false;
        }

        $applied = DB::table('migrations')->count();
        $onDisk = count(glob(database_path('migrations/*.php')) ?: []);

        return $applied >= $onDisk;
    }

    /** Legacy `RECOMMENDED_FREE_SPACE = 10` GB. */
    private function hasFreeSpace(): bool
    {
        $free = disk_free_space(storage_path());

        return $free === false || $free > 10 * 1024 * 1024 * 1024;
    }
}
