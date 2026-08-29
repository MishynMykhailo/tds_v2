<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_stream_filters` 1-to-1 (confirmed via DESCRIBE
 * against the live old backend). No own ACL entity type — access is always
 * via the parent Stream -> Campaign chain (same pattern as Stream itself).
 * See docs/legacy-reference/frontend/api/10.2_streams.md ("StreamFilters").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('mode', 40); // e.g. "or"/"and" — confirm exact values against real usage when porting the click pipeline
            $table->text('payload')->nullable(); // JSON string, decoded via StreamFilter::getPayload() in legacy

            $table->index('stream_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_filters');
    }
};
