<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_traffic_sources` 1-to-1 (confirmed via DESCRIBE
 * against the live old backend). See
 * docs/legacy-reference/frontend/api/10.6_trafficsources.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traffic_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('postback_url')->nullable();
            $table->string('postback_statuses')->nullable();
            $table->string('template_name')->nullable();
            $table->boolean('accept_parameters')->default(true);
            $table->text('parameters')->nullable(); // JSON string
            $table->string('state')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('traffic_loss', 4, 2)->default(0);
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('traffic_sources');
    }
};
