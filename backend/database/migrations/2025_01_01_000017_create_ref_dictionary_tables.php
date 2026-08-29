<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `clicks`/`conversions` tables (next migrations) store many
 * high-cardinality string fields (referrer URL, keyword, search engine,
 * creative id, ...) as small integer FKs into these dictionary tables
 * instead of repeating the string on every row — string-interning for a
 * high-volume tracking table. Confirmed via DESCRIBE against the live old
 * backend: `tds_ref_{referrers,sources,keywords,search_engines,
 * creative_ids,external_ids,ad_campaign_ids,x_requested_with,destinations}`
 * — all 9 share the IDENTICAL shape (id + unique value), so they're
 * created together here rather than as 9 separate migration files.
 */
return new class extends Migration
{
    private const TABLES = [
        'ref_referrers',
        'ref_sources',
        'ref_keywords',
        'ref_search_engines',
        'ref_creative_ids',
        'ref_external_ids',
        'ref_ad_campaign_ids',
        'ref_x_requested_with',
        'ref_destinations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('value')->unique();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
