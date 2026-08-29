<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_clicks` 1-to-1 (confirmed via DESCRIBE against the
 * live old backend — ~65 columns). This is the core click-tracking fact
 * table; `*_id` columns (source_id, referrer_id, search_engine_id,
 * keyword_id, creative_id_id, external_id_id, ad_campaign_id_id,
 * x_requested_with_id, destination_id) are FKs into the `ref_*` dictionary
 * tables (previous migration), NOT free-text columns — this is how legacy
 * avoids storing repeated long strings on a high-volume table.
 *
 * NOTE: nothing writes to this table yet — that's the click-processing
 * pipeline's job (traffic-core/, not started). This migration exists so
 * the Conversions/admin-side log-viewing endpoints and (later) Grid/Reports
 * have a real schema to query against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clicks', function (Blueprint $table) {
            $table->id('click_id');
            $table->unsignedBigInteger('visitor_id');
            $table->string('sub_id')->unique();
            $table->unsignedInteger('ts_id')->nullable();
            $table->unsignedInteger('landing_id')->nullable();
            $table->boolean('landing_clicked')->default(false);
            $table->dateTime('landing_clicked_datetime')->nullable();
            $table->unsignedInteger('offer_id')->nullable();
            $table->unsignedInteger('affiliate_network_id')->nullable();
            $table->dateTime('datetime');
            $table->unsignedInteger('campaign_id');
            $table->unsignedInteger('parent_campaign_id')->nullable();
            $table->unsignedInteger('stream_id')->nullable();
            $table->boolean('is_unique_stream')->default(false);
            $table->boolean('is_unique_campaign')->default(false);
            $table->boolean('is_unique_global')->default(false);
            $table->boolean('is_bot')->default(false);
            $table->boolean('is_using_proxy')->default(false);
            $table->boolean('is_empty_referrer')->default(false);
            $table->unsignedInteger('source_id');
            $table->integer('referrer_id');
            $table->integer('search_engine_id')->nullable();
            $table->unsignedInteger('keyword_id')->nullable();

            for ($i = 1; $i <= 15; $i++) {
                $table->unsignedInteger("sub_id_{$i}_id")->nullable();
            }
            for ($i = 1; $i <= 10; $i++) {
                $table->string("extra_param_{$i}")->nullable();
            }

            $table->decimal('lead_revenue', 13, 4)->default(0);
            $table->decimal('rejected_revenue', 13, 4)->default(0);
            $table->decimal('sale_revenue', 13, 4)->default(0);
            $table->decimal('cost', 13, 6)->default(0);
            $table->boolean('is_lead')->default(false);
            $table->boolean('is_sale')->default(false);
            $table->boolean('is_rejected')->default(false);
            $table->unsignedInteger('rebills')->default(0);
            $table->unsignedBigInteger('destination_id')->nullable();
            $table->unsignedInteger('creative_id_id')->nullable();
            $table->unsignedInteger('external_id_id')->nullable();

            for ($i = 1; $i <= 4; $i++) {
                $table->boolean("goal_{$i}")->default(false);
                $table->dateTime("goal_{$i}_datetime")->nullable();
            }

            $table->unsignedInteger('ad_campaign_id_id')->nullable();
            $table->unsignedInteger('x_requested_with_id')->nullable();

            $table->index('visitor_id');
            $table->index('ts_id');
            $table->index('landing_id');
            $table->index('offer_id');
            $table->index('affiliate_network_id');
            $table->index('datetime');
            $table->index('campaign_id');
            $table->index('parent_campaign_id');
            $table->index('stream_id');
            $table->index('source_id');
            $table->index('referrer_id');
            $table->index('search_engine_id');
            $table->index('keyword_id');
            $table->index('destination_id');
            $table->index('creative_id_id');
            $table->index('external_id_id');
            $table->index('ad_campaign_id_id');
            $table->index('x_requested_with_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clicks');
    }
};
