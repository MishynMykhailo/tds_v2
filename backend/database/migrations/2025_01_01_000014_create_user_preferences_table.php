<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_user_preferences` 1-to-1 (confirmed via DESCRIBE) —
 * simple key/value store per user (e.g. UI language, see the `LANGUAGE`
 * preference read in AdminContext::_switchLocale() in the old codebase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pref_name', 50);
            $table->text('pref_value')->nullable();

            $table->unique(['user_id', 'pref_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
