<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_offers` 1-to-1 (confirmed via DESCRIBE against the
 * live old backend). See
 * docs/legacy-reference/frontend/backend_api_reference.md §10.3 (Offers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('group_id')->nullable();
            $table->text('action_payload')->nullable();
            $table->unsignedBigInteger('affiliate_network_id')->nullable();
            $table->decimal('payout_value', 10, 4)->default(0);
            $table->string('payout_currency', 3)->nullable();
            $table->string('payout_type', 10)->nullable(); // CPA|CPC|RevShare
            $table->string('state')->nullable();
            $table->boolean('payout_auto')->default(false);
            $table->boolean('payout_upsell')->default(false);
            $table->string('country')->nullable();
            $table->text('notes')->nullable();
            $table->text('action_options')->nullable();
            $table->string('action_type', 50)->nullable();
            $table->string('offer_type', 10)->default('external'); // external|local
            $table->text('url')->nullable();
            $table->boolean('conversion_cap_enabled')->default(false);
            $table->unsignedInteger('daily_cap')->default(0);
            $table->string('conversion_timezone', 50)->default('UTC');
            $table->integer('alternative_offer_id')->nullable();
            $table->timestamps();

            $table->index('state');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
