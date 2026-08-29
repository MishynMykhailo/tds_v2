<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_domains` 1-to-1 (confirmed via DESCRIBE against the
 * live old backend). See docs/legacy-reference/frontend/api/10.7_domains.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domains', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_ssl')->default(false);
            $table->string('network_status')->nullable(); // validating|active|error
            $table->unsignedBigInteger('default_campaign_id')->nullable();
            $table->string('state')->nullable();
            $table->boolean('wildcard')->default(false);
            $table->boolean('catch_not_found')->default(true);
            $table->text('notes')->nullable();
            $table->string('error_description')->nullable();
            $table->string('ssl_status')->nullable();
            $table->string('redirect')->default('not'); // legacy: "not"|"https" (-> ssl_redirect)
            $table->text('ssl_data')->nullable();
            $table->boolean('is_robots_allowed')->default(true);
            $table->dateTime('next_check_at')->nullable();
            $table->boolean('ssl_redirect')->default(false);
            $table->boolean('allow_indexing')->default(true);
            $table->unsignedInteger('check_retries')->default(0);
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domains');
    }
};
