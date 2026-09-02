<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New table — legacy's `Component\Campaigns\Repository\
 * CampaignPostbackRepository` (referenced from `SendPostbacksStage::
 * _processCampaignPostbacks()`, application/Component/Postback/
 * ProcessPostback/Stages/SendPostbacksStage.php) reads a
 * `campaign_postbacks`-shaped table that does not exist in the legacy
 * schema dump this project started from (confirmed via
 * `SHOW TABLES LIKE '%postback%'` against the live `tds2` dev DB — empty
 * before this migration). This is a from-scratch table for the
 * traffic-core S2S postback port (see `TrafficCore\Postback\
 * OutboundPostbackService`).
 *
 * No DB-enforced FK on `campaign_id` — matches this project's established
 * style for association-ish tables that traffic-core (a non-Eloquent, raw
 * PDO consumer) also reads directly; see
 * `2025_01_01_000027_create_stream_landing_associations_table.php` for
 * the FK-vs-no-FK precedent this migration deliberately diverges from:
 * that one DOES use `foreignId()->constrained()` because both sides
 * (streams/landings) are exclusively Laravel-managed. `campaigns` here is
 * also Laravel-managed, but traffic-core's own migration-free raw-PDO
 * reads/writes elsewhere in this project (`clicks`, `conversions`) never
 * carry FK constraints either — kept consistent with THAT half of the
 * schema instead, since `campaign_postbacks` is read by traffic-core, not
 * just backend/.
 *
 * `statuses` format: JSON array string (e.g. `["sale","lead"]`), matching
 * the exact convention already used by `traffic_sources.postback_statuses`
 * (confirmed in `TrafficSourcesController`'s `decodeJsonField()`/
 * `encodeJsonFieldForWrite()`) — see `OutboundPostbackService`'s docblock.
 *
 * No admin UI for managing this table is built in this task (out of
 * scope, per task description) — rows are inserted directly for now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_postbacks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id');
            $table->text('url');
            $table->string('method', 10)->default('GET');
            $table->string('statuses')->nullable();
            $table->timestamps();

            $table->index('campaign_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_postbacks');
    }
};
