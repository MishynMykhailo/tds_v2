<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_click_links` 1-to-1 (confirmed via DESCRIBE) — links a
 * click's sub_id to a parent click's sub_id (multi-hop funnels / stream
 * chaining, e.g. "to_campaign" stream actions).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('click_links', function (Blueprint $table) {
            $table->id();
            $table->string('sub_id');
            $table->string('parent_sub_id');

            $table->index('sub_id');
            $table->index('parent_sub_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('click_links');
    }
};
