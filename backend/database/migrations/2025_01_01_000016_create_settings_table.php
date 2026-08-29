<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_settings` 1-to-1 — flat key/value store, `key` is the
 * primary key (confirmed via DESCRIBE). See
 * docs/legacy-reference/frontend/api/10.12_settings.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
