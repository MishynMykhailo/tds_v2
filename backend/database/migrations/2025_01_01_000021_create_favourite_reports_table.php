<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_favourite_reports` 1-to-1 (confirmed via DESCRIBE).
 * `payload` is a JSON string (saved QueryParams for a bookmarked report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favourite_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_shared')->default(false);
            $table->text('payload');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourite_reports');
    }
};
