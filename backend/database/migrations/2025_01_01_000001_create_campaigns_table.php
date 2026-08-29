<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Schema mirrors the legacy `tds_campaigns` table 1-to-1 — confirmed by
 * reading the live old backend's DB directly (not guessed). See
 * docs/legacy-reference/frontend/backend_api_reference.md §10.1 for the API
 * contract this table serves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('alias')->unique();
            $table->string('name');
            $table->string('type')->default('weight');
            $table->string('uniqueness_method')->default('ip_ua');
            $table->unsignedInteger('cookies_ttl')->default(0);
            $table->unsignedTinyInteger('action_type')->default(0);
            $table->text('action_payload')->nullable();
            $table->string('action_for_bots')->default('404');
            $table->string('bot_redirect_url')->nullable();
            $table->text('bot_text')->nullable();
            $table->boolean('action_tracking_disabled')->default(false);
            $table->unsignedInteger('position')->default(9999);
            $table->string('state')->default('active');
            $table->string('mode')->default('general');
            $table->string('cost_type')->default('CPC');
            $table->decimal('cost_value', 12, 4)->default(0);
            $table->string('cost_currency')->default('USD');
            $table->unsignedBigInteger('group_id')->default(0);
            $table->string('bind_visitors')->nullable();
            $table->unsignedBigInteger('traffic_source_id')->default(0);
            $table->string('token')->nullable();
            $table->boolean('cost_auto')->default(true);
            $table->unsignedBigInteger('domain_id')->default(0);
            $table->text('notes')->nullable();
            $table->json('parameters')->nullable();
            $table->boolean('uniqueness_use_cookies')->default(true);
            $table->decimal('traffic_loss', 5, 2)->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('state');
            $table->index('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
