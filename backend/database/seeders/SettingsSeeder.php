<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

/**
 * Port of legacy `application/data/data.sql`'s 52 `INSERT IGNORE INTO
 * \`tds_settings\`` rows — literal key/value pairs, copied verbatim
 * (values as strings, matching the `settings` table's `value` column
 * type). `INSERT IGNORE` semantics replicated via `updateOrCreate`
 * skipped-if-present logic below (`firstOrCreate`, not `updateOrCreate`
 * — a re-run must never clobber a value an admin already changed via the
 * UI, exactly like legacy's own idempotent seed).
 *
 * Found missing live (2026-09-03, via tests-contract/ finally running
 * against this backend — see docs/PORTING_LOG.md): the `settings` table
 * had essentially NO default rows at all (only one, unrelated to this
 * list) — every one of these 52 keys was silently absent, breaking
 * `settings.find`/`settings.index` contract tests and leaving every
 * consuming controller to fall back on its own in-code default (where
 * one existed) or nothing at all.
 *
 * A handful of legacy keys are kept here even though nothing in this
 * project's code currently reads them (`cache_storage`, `memcached_server`,
 * `av_service`, `operators_service`, `data_storage`, etc. — legacy-infra
 * settings this rewrite doesn't have equivalents for yet) — the
 * `settings.index`/`.find` contract is "reflect this table", not "only
 * keys the current code happens to use", so dropping them would itself
 * be a contract regression even though they're inert here.
 */
class SettingsSeeder extends Seeder
{
    /** @var array<string, string> */
    private const DEFAULTS = [
        'check_bot_ip' => '1',
        'check_bot_referer' => '0',
        'check_bot_empty_ua' => '0',
        'check_bot_ua' => '1',
        'check_bot_prefetch' => '0',
        'disable_stats' => '0',
        'cache_storage' => 'files',
        'memcached_server' => 'localhost:11211',
        'redis_server' => '127.0.0.1:6379/1',
        'av_service' => 'avscan',
        'operators_service' => 'carrierdb',
        'geodb' => 'ip2location_lite',
        'links_style' => 'new',
        'currency' => 'USD',
        'id_aliases' => 'id, g, group, sid',
        'keyword_aliases' => 'keyword, utm_term, utm_kwd',
        'referrer_aliases' => 'referrer, referer',
        'se_aliases' => '',
        'se_referrer_aliases' => 'se_referer, se_referrer, seoref',
        'source_aliases' => 'source, utm_source',
        'sub_id_1_aliases' => '',
        'sub_id_2_aliases' => '',
        'sub_id_3_aliases' => '',
        'sub_id_4_aliases' => '',
        'draft_data_storage' => 'redis',
        'extra_action' => 'not_found',
        'detect_spam_bots' => '0',
        'stats_ttl' => '256',
        'archive_ttl' => '60',
        'lp_dir' => 'lander',
        'lp_allow_php' => '1',
        'lp_php_timeout' => '1',
        'force_token_files' => '0',
        'show_extra_param' => '0',
        'is_sidebar_enabled' => '1',
        'avoid_mysql' => '1',
        'cookies_enabled' => '1',
        'ipdb' => '{"0":null,"1":null,"2":null,"3":null,"4":null,"5":null,"6":null,"7":null,"8":null,"country":"ip2location_lite","region":"ip2location_lite","city":"ip2location_lite","city_ru":null,"connection_type":"tds_carrier","operator":"tds_carrier","bot_type":"tds_bot_db2","isp":null,"proxy_type":null}',
        'conversions_2_previous_conversion_id_exists' => '1',
        'conversions_2_sub_id_15_id_exists' => '1',
        'conversions_2_x_requested_with_id_exists' => '1',
        'conversions_2_affiliate_network_id_exists' => '1',
        'clicks_sub_id_15_id_exists' => '1',
        'clicks_x_requested_with_id_exists' => '1',
        'clicks_affiliate_network_id_exists' => '1',
        'is_beta_channel' => '0',
        's2s_timeout' => '5',
        'lp_offer_token_ttl' => '1440',
        'default_action_allowed' => '0',
        'campaign_autosave' => '0',
        'data_storage' => 'mysql',
        'forced_report_utc_timezone' => '1',
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }
    }
}
