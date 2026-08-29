<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_conversions_2` 1-to-1 (confirmed via DESCRIBE — the
 * "_2" suffix is legacy's own naming, likely a schema-version artifact from
 * a past migration; kept faithful, table renamed to plain `conversions`
 * here since we have no legacy "_1" table to disambiguate against and a
 * fresh schema doesn't need the versioned name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table) {
            $table->id('conversion_id');
            $table->unsignedBigInteger('visitor_id')->nullable();
            $table->integer('campaign_id')->nullable();
            $table->unsignedInteger('stream_id')->nullable();
            $table->unsignedInteger('ts_id')->nullable();
            $table->unsignedInteger('landing_id')->nullable();
            $table->unsignedInteger('offer_id')->nullable();
            $table->unsignedInteger('affiliate_network_id')->nullable();
            $table->string('sub_id');
            $table->unsignedInteger('click_id')->nullable();
            $table->string('tid', 100)->nullable();
            $table->dateTime('click_datetime');
            $table->dateTime('postback_datetime');
            $table->string('status', 100)->nullable();
            $table->string('previous_status', 100)->nullable();
            $table->string('original_status', 100)->nullable();
            $table->unsignedInteger('source_id')->nullable();
            $table->unsignedInteger('referrer_id')->nullable();
            $table->unsignedInteger('search_engine_id')->nullable();
            $table->unsignedInteger('keyword_id')->nullable();
            $table->unsignedInteger('screen_id')->nullable();

            for ($i = 1; $i <= 15; $i++) {
                $table->unsignedInteger("sub_id_{$i}_id")->nullable();
            }
            for ($i = 1; $i <= 10; $i++) {
                $table->string("extra_param_{$i}")->nullable();
            }

            $table->decimal('revenue', 10, 4)->default(0);
            $table->decimal('cost', 13, 6)->default(0);
            $table->text('params')->nullable(); // JSON string
            $table->boolean('is_processed')->default(false);
            $table->unsignedInteger('creative_id_id')->nullable();
            $table->unsignedInteger('external_id_id')->nullable();
            $table->unsignedInteger('ad_campaign_id_id')->nullable();
            $table->dateTime('sale_datetime')->nullable();
            $table->unsignedInteger('x_requested_with_id')->nullable();
            $table->unsignedInteger('previous_conversion_id')->nullable();

            $table->index('campaign_id');
            $table->index('sub_id');
            $table->index('affiliate_network_id');
            $table->index('x_requested_with_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversions');
    }
};
