<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_affiliate_networks` 1-to-1 (confirmed via DESCRIBE).
 * ACL key confirmed in AclService.php's docblock: "affiliate_networks".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_networks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('postback_url')->nullable();
            $table->string('offer_param')->nullable();
            $table->string('state')->nullable();
            $table->string('template_name')->nullable();
            $table->text('notes')->nullable();
            $table->text('pull_api_options')->nullable(); // JSON string
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_networks');
    }
};
