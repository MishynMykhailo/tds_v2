<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `clicks.sub_id_1_id`..`sub_id_15_id` (migration
 * 2025_01_01_000018_create_clicks_table.php) are FKs into a single
 * shared dictionary table, confirmed via `SHOW TABLES LIKE '%ref%'`
 * against the live legacy DB (`tds_ref_sub_ids`) — missed in the
 * original `2025_01_01_000017_create_ref_dictionary_tables.php` batch,
 * added here. Same `(id, value unique)` shape as that migration's 9
 * tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ref_sub_ids', function (Blueprint $t) {
            $t->id();
            $t->string('value')->unique();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ref_sub_ids');
    }
};
