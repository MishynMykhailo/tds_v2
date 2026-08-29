<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors legacy `tds_country_profiles` (real table name, confirmed
        // via live DESCRIBE against the old DB). `countries` is a
        // space-separated list of ISO country codes (legacy
        // `GeoProfileService::parseCountriesAndCreate()` does
        // `implode(" ", $codes)`), not JSON.
        Schema::create('country_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->text('countries');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_profiles');
    }
};
