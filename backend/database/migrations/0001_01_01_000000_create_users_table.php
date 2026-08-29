<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Overridden from the Laravel skeleton default: legacy auth is NOT
     * session/email based (see docs/legacy-reference/frontend/backend_api_reference.md
     * §4) — it's a JWT cookie re-verified against user_password_hashes on
     * every request. Schema mirrors legacy `tds_users` 1-to-1 (confirmed via
     * DESCRIBE against the live old backend), not Laravel's default
     * name/email/password shape.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['ADMIN', 'USER'])->default('USER');
            $table->string('login', 50)->unique();
            $table->string('password', 32)->nullable(); // legacy md5, unused for new writes
            $table->string('password_hash')->nullable(); // bcrypt
            $table->text('rules')->nullable();
            $table->string('permissions', 250)->nullable();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
