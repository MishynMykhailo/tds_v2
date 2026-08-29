<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_labels` 1-to-1 (confirmed via DESCRIBE) — user-defined
 * tags attached to a specific dimension value (e.g. "this referrer =
 * suspicious") within a campaign, used by the HAS_LABEL/HAS_NOT_LABEL grid
 * filter operators (see App\Services\Grid\FilterOperator — currently a
 * no-op TODO there pending this table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('campaign_id')->nullable();
            $table->string('label_name', 50);
            $table->string('ref_name', 100);
            $table->unsignedInteger('ref_id')->nullable();
            $table->string('ref_value')->nullable();

            $table->index('campaign_id');
            $table->index('label_name');
            $table->index('ref_name');
            $table->index('ref_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('labels');
    }
};
