<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ---------------------------------------------------------------
// Maintenance jobs — ports of legacy Cron/PruneTask tasks (see
// docs/BACKEND_REMAINING_WORK.md section 2, docs/PORTING_LOG.md for the
// full triage of which of the ~26 legacy Cron/PruneTask classes this
// covers vs. deliberately skips and why). Each command is a no-op when
// its governing setting (archive_ttl/stats_ttl) is unset, matching
// legacy's own safe-by-default behavior.
//
// Real legacy intervals, all ported as their closest Laravel Scheduler
// equivalent: PruneArchive (ARCHIVE_TYPE tasks) = 24h ->
// `->daily()`; PruneClicks = 24h -> `->daily()`; PruneData
// (GENERAL_TYPE, bundles PruneUserPasswordHash here) = 1440 min = 24h ->
// `->daily()`. PruneOrphanedData has no direct legacy cron of its own
// (invoked via the weekly `Grid\CronTask\PruneReferences`, 03:00-04:00
// admin-timezone window) — scheduled `->daily()->at('03:30')` here as a
// reasonable equivalent (safe UTC-server-time slot, no per-admin-
// timezone lookup infra exists in this project to replicate the exact
// window check).
//
// NOT ported: legacy `Triggers\CronTask\DeleteOldTriggers` (orphaned
// trigger cleanup) — found while implementing it that this project's
// `triggers.stream_id` has a REAL `->constrained()->cascadeOnDelete()`
// foreign key (see database/migrations/2025_01_01_000009_create_triggers_table.php),
// unlike legacy's schema. A stream delete already cascades to its
// triggers here, so an orphaned trigger (stream_id pointing at a
// nonexistent stream) is structurally impossible in this schema — the
// cleanup command would always be a provable no-op, not a real gap.
//
// Added later (2026-09-03, backlog "tails" pass): `PruneStreamEvents`
// (monitoring_history, 30-day literal legacy period) and
// `PruneHitLimits` (rate:<stream_id> Redis sets, 1-day legacy TTL) -
// both previously listed as blocked, re-verified live that neither
// actually was (their underlying tables/mechanisms were already real).
// Both are also GENERAL_TYPE prune tasks in legacy's own PruneData
// (1440 min = 24h) - `->daily()`, same as every other command here.
//
// Same round, second pass (user asked to actually build the
// ConversionCapacity module rather than skip it): `PruneDailyCap`
// (daily_cap:<offer_id> Redis sets, 2-day legacy TTL) - the offer
// daily-cap feature itself is now real (see
// TrafficCore\Pipeline\ChooseOfferStage / App\Services\
// ConversionCapacityService docblocks), not just this prune command.
// PruneUserBotDBCA/PruneLandingOfferCache remain deliberately NOT
// ported - confirmed with the user directly that DBCA's binary
// IP-range cache format is a pure performance optimization with no
// behavioral difference from the already-real `check_bot_ip` SQL
// check, and the file-based lp-offer cache architecture doesn't exist
// in this project at all.
// ---------------------------------------------------------------

Schedule::command('app:prune-archived-entities')->daily();
Schedule::command('app:prune-click-stats')->daily();
Schedule::command('app:prune-expired-password-hashes')->daily();
Schedule::command('app:prune-orphaned-data')->daily()->at('03:30');
Schedule::command('app:prune-stream-events')->daily();
Schedule::command('app:prune-hit-limits')->daily();
Schedule::command('app:prune-daily-cap')->daily();
