<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_triggers` 1-to-1 (confirmed via DESCRIBE against the
 * live old backend). No own ACL entity type — access via parent Stream ->
 * Campaign chain. See docs/legacy-reference/frontend/api/10.2_streams.md
 * ("Triggers").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('triggers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->string('target', 50);
            $table->string('condition', 100);
            $table->string('selected_page')->nullable();
            $table->string('pattern')->nullable();
            $table->string('action', 100);
            $table->unsignedInteger('interval');
            $table->unsignedInteger('next_run_at')->nullable(); // legacy stores unix timestamp, not a DATETIME
            $table->text('alternative_urls')->nullable();
            $table->string('grab_from_page', 250)->nullable();
            $table->text('av_settings')->nullable();
            $table->boolean('reverse')->default(false);
            $table->boolean('enabled')->default(false);
            $table->boolean('scan_page')->default(false);

            $table->index('stream_id');
            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('triggers');
    }
};
