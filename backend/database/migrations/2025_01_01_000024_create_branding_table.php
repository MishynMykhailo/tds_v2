<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_branding` 1-to-1 (confirmed via DESCRIBE) — a
 * single-row table (white-label logo/favicon). Legacy gates this behind a
 * fake license feature flag (`hasBrandingFeature()`) — this port has no
 * license gating at all (see docs/PORTING_LOG.md / project_identity memory
 * re: the neutralized license system), so the feature is simply always
 * available.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding', function (Blueprint $table) {
            $table->id();
            $table->binary('logo')->nullable();
            $table->binary('favicon')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding');
    }
};
