<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_favourite_streams` 1-to-1 (confirmed via DESCRIBE).
 * See docs/legacy-reference/frontend/api/10.2_streams.md ("FavouriteStreams").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('favourite_streams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourite_streams');
    }
};
