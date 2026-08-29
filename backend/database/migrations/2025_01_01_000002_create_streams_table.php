<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema mirrors the legacy `tds_streams` table 1-to-1 — confirmed by
 * `DESCRIBE tds_streams` against the live old backend's DB (not guessed).
 * See docs/legacy-reference/frontend/backend_api_reference.md §10.2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('streams', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50)->default('regular');
            $table->string('name', 100)->nullable();
            $table->foreignId('campaign_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('group_id')->default(0);
            $table->integer('position')->default(1);
            $table->text('action_options')->nullable();
            $table->text('comments')->nullable();
            $table->string('state', 50)->default('active');
            $table->string('action_type', 50)->nullable();
            $table->mediumText('action_payload')->nullable();
            $table->string('schema', 30)->nullable();
            $table->boolean('collect_clicks')->default(true);
            $table->boolean('filter_or')->default(false);
            $table->unsignedInteger('weight')->default(0);
            $table->integer('chance')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('state');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('streams');
    }
};
