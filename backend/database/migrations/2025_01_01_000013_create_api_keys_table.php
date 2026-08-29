<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_api_keys` 1-to-1 (confirmed via DESCRIBE). Used by
 * the REST layer (`/admin_api/vN/...`) for `?api_key=`/`Api-Key:` auth —
 * see docs/legacy-reference/frontend/api/00_common_routing_auth_acl_errors_grid.md
 * §3. `user_id` is nullable in legacy (unlike most FKs), kept faithful.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('datetime');

            $table->index('key');
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
