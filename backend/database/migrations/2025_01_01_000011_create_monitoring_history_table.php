<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_monitoring_history` 1-to-1 (confirmed via DESCRIBE).
 * NOTE: the API object is `streamEvents`, but the underlying legacy model
 * (`Component\Streams\Model\StreamEvent`) maps to table `monitoring_history`
 * — NOT `stream_events`, which doesn't exist. Do not rename this table to
 * match the API object name. See
 * docs/legacy-reference/frontend/api/10.2_streams.md ("StreamEvents").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_history', function (Blueprint $table) {
            $table->id();
            $table->string('level', 10); // "info"|"warning"
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('trigger_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            $table->dateTime('date');
            $table->enum('state', ['unread', 'read'])->default('read');

            $table->index('stream_id');
            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_history');
    }
};
