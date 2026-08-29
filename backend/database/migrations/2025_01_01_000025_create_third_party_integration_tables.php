<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors legacy `tds_third_party_integration` (real table name —
        // NOT `third_party_integration` as the model's `$_tableName` literal
        // would suggest; confirmed via live DESCRIBE against the old DB).
        Schema::create('third_party_integration', function (Blueprint $table) {
            $table->id();
            $table->string('integration');
            $table->text('settings')->nullable();
            $table->timestamps();
        });

        // Mirrors legacy `tds_third_party_integration_campaign_associations`.
        Schema::create('third_party_integration_campaign_associations', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('integration_id')->index();
            $table->unsignedInteger('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('third_party_integration_campaign_associations');
        Schema::dropIfExists('third_party_integration');
    }
};
