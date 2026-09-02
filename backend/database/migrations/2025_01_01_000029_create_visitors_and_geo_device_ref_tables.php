<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Port of legacy `tds_visitors` + its geo/device dictionary tables
 * (confirmed via `DESCRIBE tds_visitors` and `SHOW TABLES LIKE '%ref%'`
 * against the live old backend — same string-interning pattern as
 * `2025_01_01_000017_create_ref_dictionary_tables.php`'s 9 tables, just
 * for a different `clicks` concern: geo/device/ISP data lives on the
 * VISITOR, not the click — `clicks.visitor_id` FKs here; `clicks` itself
 * has no country/browser/os/etc. columns at all in legacy either).
 *
 * All ref_ tables below share the identical `(id, value unique)` shape,
 * same as the Phase-... dictionaries — confirmed for
 * countries/regions/cities/browsers/os/device_types/ips/user_agents/
 * languages via live `DESCRIBE`; browser_versions/os_versions/
 * connection_types/operators/isp/device_models assumed identical by the
 * established naming convention, not independently re-verified column
 * -by-column — spot-check before assuming exotic types if a value ever
 * fails to insert.
 *
 * `ref_ips.value` is an unsigned int (packed IPv4 via legacy's
 * `ip2long`-style storage) — NOT a string, unlike every other ref_ table
 * in this project. IPv6 handling is a known unresolved question already
 * flagged elsewhere in this project (traffic-core's Phase-4 IPv6 CIDR
 * work) — out of scope here, `ip_id` may simply stay null for IPv6
 * visitors until that's revisited.
 */
return new class extends Migration
{
    private const STRING_VALUE_TABLES = [
        'ref_user_agents',
        'ref_countries',
        'ref_regions',
        'ref_cities',
        'ref_device_types',
        'ref_device_models',
        'ref_languages',
        'ref_browsers',
        'ref_browser_versions',
        'ref_os',
        'ref_os_versions',
        'ref_connection_types',
        'ref_operators',
        'ref_isp',
    ];

    public function up(): void
    {
        Schema::create('ref_ips', function (Blueprint $t) {
            $t->id();
            $t->unsignedInteger('value')->unique();
        });

        foreach (self::STRING_VALUE_TABLES as $table) {
            Schema::create($table, function (Blueprint $t) {
                $t->id();
                $t->string('value')->nullable()->unique();
            });
        }

        Schema::create('visitors', function (Blueprint $t) {
            $t->id();
            $t->string('visitor_code')->unique();
            $t->foreignId('ip_id')->constrained('ref_ips');
            $t->foreignId('user_agent_id')->constrained('ref_user_agents');
            $t->foreignId('country_id')->nullable()->constrained('ref_countries');
            $t->foreignId('region_id')->nullable()->constrained('ref_regions');
            $t->foreignId('city_id')->nullable()->constrained('ref_cities');
            $t->foreignId('device_type_id')->nullable()->constrained('ref_device_types');
            $t->foreignId('device_model_id')->nullable()->constrained('ref_device_models');
            $t->foreignId('language_id')->nullable()->constrained('ref_languages');
            $t->foreignId('browser_id')->nullable()->constrained('ref_browsers');
            $t->foreignId('browser_version_id')->nullable()->constrained('ref_browser_versions');
            $t->foreignId('os_id')->nullable()->constrained('ref_os');
            $t->foreignId('os_version_id')->nullable()->constrained('ref_os_versions');
            $t->foreignId('connection_type_id')->nullable()->constrained('ref_connection_types');
            $t->foreignId('operator_id')->nullable()->constrained('ref_operators');
            $t->foreignId('isp_id')->nullable()->constrained('ref_isp');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visitors');
        foreach (array_reverse(self::STRING_VALUE_TABLES) as $table) {
            Schema::dropIfExists($table);
        }
        Schema::dropIfExists('ref_ips');
    }
};
