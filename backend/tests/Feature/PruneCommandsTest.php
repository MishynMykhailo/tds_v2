<?php

use App\Models\Click;
use App\Models\ClickLink;
use App\Models\Conversion;
use App\Models\Setting;
use App\Models\StreamEvent;
use App\Models\UserPasswordHash;
use Database\Factories\CampaignFactory;
use Database\Factories\TriggerFactory;
use Database\Factories\UserFactory;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Console maintenance commands (backlog section 2 — Console/Cron)
|--------------------------------------------------------------------------
|
| Ports of a curated subset of legacy Cron/PruneTask classes — see each
| command's own docblock for the exact legacy source and the full triage
| of what's ported vs. skipped (docs/PORTING_LOG.md has the complete
| list). RefreshDatabase is applied to the whole Feature suite in
| tests/Pest.php.
*/

it('app:prune-archived-entities does nothing when archive_ttl is unset', function () {
    $campaign = CampaignFactory::new()->create(['state' => 'deleted']);
    DB::table('campaigns')->where('id', $campaign->id)->update(['updated_at' => now()->subDays(999)]);

    $this->artisan('app:prune-archived-entities')->assertExitCode(0);

    expect(\App\Models\Campaign::find($campaign->id))->not->toBeNull();
});

it('app:prune-archived-entities deletes only deleted rows older than archive_ttl', function () {
    Setting::create(['key' => 'archive_ttl', 'value' => '5']);

    $old = CampaignFactory::new()->create(['state' => 'deleted']);
    DB::table('campaigns')->where('id', $old->id)->update(['updated_at' => now()->subDays(10)]);

    $recent = CampaignFactory::new()->create(['state' => 'deleted']);
    DB::table('campaigns')->where('id', $recent->id)->update(['updated_at' => now()->subDay()]);

    $active = CampaignFactory::new()->create(['state' => 'active']);
    DB::table('campaigns')->where('id', $active->id)->update(['updated_at' => now()->subDays(999)]);

    $this->artisan('app:prune-archived-entities')->assertExitCode(0);

    expect(\App\Models\Campaign::find($old->id))->toBeNull();
    expect(\App\Models\Campaign::find($recent->id))->not->toBeNull();
    expect(\App\Models\Campaign::find($active->id))->not->toBeNull();
});

it('app:prune-click-stats does nothing when stats_ttl is unset', function () {
    Click::create([
        'visitor_id' => 1, 'sub_id' => 'stats-ttl-unset', 'datetime' => now()->subDays(999),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);

    $this->artisan('app:prune-click-stats')->assertExitCode(0);

    expect(Click::where('sub_id', 'stats-ttl-unset')->exists())->toBeTrue();
});

it('app:prune-click-stats deletes clicks/conversions older than stats_ttl (dispatches DeleteStatsJob synchronously)', function () {
    Setting::create(['key' => 'stats_ttl', 'value' => '30']);

    $old = Click::create([
        'visitor_id' => 1, 'sub_id' => 'stats-old', 'datetime' => now()->subDays(60),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);
    Click::create([
        'visitor_id' => 1, 'sub_id' => 'stats-recent', 'datetime' => now()->subDays(5),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);
    Conversion::create([
        'campaign_id' => 1, 'click_id' => $old->click_id, 'sub_id' => 'stats-old',
        'click_datetime' => now()->subDays(60), 'postback_datetime' => now()->subDays(60), 'status' => 'sale',
        'revenue' => 10, 'cost' => 1,
    ]);

    $this->artisan('app:prune-click-stats')->assertExitCode(0);

    expect(Click::where('sub_id', 'stats-old')->exists())->toBeFalse();
    expect(Click::where('sub_id', 'stats-recent')->exists())->toBeTrue();
    expect(Conversion::where('sub_id', 'stats-old')->exists())->toBeFalse();
});

it('app:prune-orphaned-data deletes visitors/conversions/click_links with no matching click', function () {
    $click = Click::create([
        'visitor_id' => 777, 'sub_id' => 'orphan-keep', 'datetime' => now(),
        'campaign_id' => 1, 'source_id' => 1, 'referrer_id' => 1,
        'cost' => 0, 'lead_revenue' => 0, 'sale_revenue' => 0, 'rejected_revenue' => 0,
        'is_lead' => false, 'is_sale' => false, 'is_rejected' => false,
    ]);

    $ipId = DB::table('ref_ips')->insertGetId(['value' => 2130706433]);
    $uaId = DB::table('ref_user_agents')->insertGetId(['value' => 'orphan-test-ua']);

    DB::table('visitors')->insert([
        'id' => 777, 'visitor_code' => 'kept-visitor', 'ip_id' => $ipId, 'user_agent_id' => $uaId,
    ]);
    DB::table('visitors')->insert([
        'id' => 888, 'visitor_code' => 'orphan-visitor', 'ip_id' => $ipId, 'user_agent_id' => $uaId,
    ]);

    Conversion::create([
        'campaign_id' => 1, 'click_id' => 999999, 'sub_id' => 'orphan-conv',
        'click_datetime' => now(), 'postback_datetime' => now(), 'status' => 'sale',
        'revenue' => 10, 'cost' => 1,
    ]);

    ClickLink::create(['sub_id' => 'no-such-click-sub-id', 'parent_sub_id' => 'orphan-keep']);
    ClickLink::create(['sub_id' => 'orphan-keep', 'parent_sub_id' => 'no-such-click-sub-id']);

    $this->artisan('app:prune-orphaned-data')->assertExitCode(0);

    expect(DB::table('visitors')->where('id', 777)->exists())->toBeTrue();
    expect(DB::table('visitors')->where('id', 888)->exists())->toBeFalse();
    expect(Conversion::where('sub_id', 'orphan-conv')->exists())->toBeFalse();
    expect(ClickLink::where('sub_id', 'no-such-click-sub-id')->exists())->toBeFalse();
    expect(ClickLink::where('sub_id', 'orphan-keep')->exists())->toBeTrue();
});

it('app:prune-expired-password-hashes deletes only expired rows', function () {
    $user = UserFactory::new()->create();
    $expired = UserPasswordHash::create(['user_id' => $user->id, 'password_hash' => 'x', 'expires_at' => now()->subDay()]);
    $valid = UserPasswordHash::create(['user_id' => $user->id, 'password_hash' => 'y', 'expires_at' => now()->addDay()]);

    $this->artisan('app:prune-expired-password-hashes')->assertExitCode(0);

    expect(UserPasswordHash::find($expired->id))->toBeNull();
    expect(UserPasswordHash::find($valid->id))->not->toBeNull();
});

it('app:prune-stream-events deletes only monitoring_history rows older than 30 days', function () {
    $trigger = TriggerFactory::new()->create();

    $old = StreamEvent::create([
        'level' => 'info', 'stream_id' => $trigger->stream_id, 'trigger_id' => $trigger->id,
        'message' => 'old', 'date' => now()->subDays(31), 'state' => 'read',
    ]);
    $recent = StreamEvent::create([
        'level' => 'info', 'stream_id' => $trigger->stream_id, 'trigger_id' => $trigger->id,
        'message' => 'recent', 'date' => now()->subDays(29), 'state' => 'read',
    ]);

    $this->artisan('app:prune-stream-events')->assertExitCode(0);

    expect(StreamEvent::find($old->id))->toBeNull();
    expect(StreamEvent::find($recent->id))->not->toBeNull();
});

// app:prune-hit-limits is NOT covered here (deliberately - not an
// oversight): it talks to a real Redis instance (the `traffic` connection,
// config/database.php), and this Pest suite is designed to run fully
// isolated on SQLite in-memory with no external services required
// (phpunit.xml sets DB_CONNECTION=sqlite/:memory: precisely for that,
// and no other test in this suite touches Redis either). Live-verified
// instead against the real tds2-redis + tds2-mysql containers
// (2026-09-03): a stream with no `limit`-filter `total` cap loses entries
// older than 1 day, one with a `total` cap keeps its full history -
// see docs/PORTING_LOG.md for the full write-up.
