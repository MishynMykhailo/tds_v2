<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_groups` 1-to-1 (confirmed via DESCRIBE). `type`
 * distinguishes which entity kind the group organizes (campaign/offer/
 * landing/... — confirm exact values when porting, GROUP_ENTITY_TYPES in
 * AclService.php already lists campaigns/offers/landings as group-aware).
 * See docs/legacy-reference/frontend/api/10.8_users_groups_acl.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedInteger('position');
            $table->string('type')->default('campaign');

            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('groups');
    }
};
