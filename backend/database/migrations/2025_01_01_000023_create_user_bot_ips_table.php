<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_user_bot_ips` 1-to-1 (confirmed via DESCRIBE) — the
 * user-maintained bot IP blacklist (BotlistController's "bot list", one of
 * two storages alongside the legacy DBCA proprietary-format storage — see
 * docs/PORTING_LOG.md re: cut vendor bot-db binaries. This is the
 * user-editable MySQL-backed list, not the vendor binary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_bot_ips', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('min_ip');
            $table->unsignedInteger('max_ip');
            $table->string('raw_value');

            $table->index('min_ip');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_bot_ips');
    }
};
