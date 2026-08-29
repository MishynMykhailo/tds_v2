<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Click;
use App\Models\Conversion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Port of legacy `Component\System\Controller\StatusController` +
 * `StatusService` (object=status).
 *
 * DELIBERATELY SIMPLIFIED (documented in docs/PORTING_LOG.md), same policy
 * as DiagnosticsController: most of legacy `StatusService::info()` reads
 * from `var/stats.json`, a file written by the legacy RoadRunner process
 * supervisor (CPU/RAM/uptime/engine-statuses/fcgi/build_info), plus
 * TokuDB status, cron "last run" heartbeat, and the DelayedCommand queue —
 * none of that infrastructure exists in this Laravel app (RoadRunner/Octane
 * is a deferred future decision, not deployed yet). Those fields are
 * omitted rather than faked. `warmupCacheAction`/`restartRoadrunnerAction`
 * are dropped entirely for the same reason (no RoadRunner process to
 * restart, no legacy CachedSettingsRepository/CachedDataRepository cache
 * layer ported).
 */
class StatusController extends Controller
{
    public function getInfoAction(Request $request): array
    {
        return [
            'clicks' => (int) Click::count(),
            'conversions' => (int) Conversion::count(),
            'free_space' => disk_free_space(base_path()),
            'total_space' => disk_total_space(base_path()),
            'db_size' => $this->dbSize(),
            'installation_method' => 'Custom',
            'php_engine' => PHP_SAPI,
        ];
    }

    public function getInstallAction(Request $request): array
    {
        return ['installation_method' => 'Custom'];
    }

    private function dbSize(): ?int
    {
        // information_schema.TABLES is MySQL-specific; tests run against
        // SQLite (see PORTING_LOG), so this is a best-effort MySQL-only
        // stat, matching legacy's own MySQL-only `Db::instance()->size()`.
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        $database = config('database.connections.'.config('database.default').'.database');

        if (! is_string($database)) {
            return null;
        }

        $row = DB::selectOne(
            'SELECT SUM(data_length + index_length) AS size FROM information_schema.TABLES WHERE table_schema = ?',
            [$database]
        );

        return $row && $row->size !== null ? (int) $row->size : null;
    }
}
