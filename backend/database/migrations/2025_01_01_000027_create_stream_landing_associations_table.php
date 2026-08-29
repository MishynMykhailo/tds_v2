<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_stream_landing_associations` 1-to-1 (confirmed via
 * DESCRIBE against the live old backend): `id`, `stream_id`, `landing_id`,
 * `state` varchar(10), `share` int, `created_at`, `updated_at`.
 *
 * Upsert key is the natural pair (stream_id, landing_id), NOT `id` — see
 * `Component\Landings\Service\StreamLandingAssociationService::assign()`
 * and `App\Http\Controllers\Admin\StreamsController::assignStreamLandings()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_landing_associations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('landing_id')->constrained()->cascadeOnDelete();
            $table->string('state', 10)->default('active');
            $table->integer('share')->default(0);
            $table->timestamps();

            $table->index('landing_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_landing_associations');
    }
};
