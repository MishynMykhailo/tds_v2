<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_landings` 1-to-1 (confirmed via DESCRIBE against the
 * live old backend). See
 * docs/legacy-reference/frontend/backend_api_reference.md §10.4 (Landings).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landings', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('action_payload')->nullable();
            $table->unsignedBigInteger('group_id')->nullable();
            $table->unsignedInteger('offer_count')->default(1);
            $table->string('state')->nullable();
            $table->string('landing_type', 10)->default('external'); // external|local
            $table->text('notes')->nullable();
            $table->text('action_options')->nullable();
            $table->string('action_type', 50)->nullable();
            $table->text('url')->nullable();
            $table->timestamps();

            $table->index('state');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landings');
    }
};
