<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirrors legacy `tds_user_password_hashes`/`tds_acl_resources`/`tds_acl`
 * 1-to-1 (confirmed via `DESCRIBE` against the live old backend). The
 * `users` table itself is created by
 * `0001_01_01_000000_create_users_table.php` (overridden from the Laravel
 * skeleton default to match the legacy schema — see that file's docblock).
 * See docs/legacy-reference/frontend/backend_api_reference.md §4 (Auth) and
 * §5 (ACL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_password_hashes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('password_hash')->nullable();
            $table->dateTime('expires_at');
        });

        Schema::create('acl_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('resources')->nullable(); // JSON array of allowed section names
        });

        Schema::create('acl_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('access_type'); // full_access|created_by_user_groups_and_selected|to_groups_and_selected|read_only
            $table->text('groups')->nullable(); // JSON array of group ids
            $table->text('entities')->nullable(); // JSON array of entity ids

            $table->index(['user_id', 'entity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acl_rules');
        Schema::dropIfExists('acl_resources');
        Schema::dropIfExists('user_password_hashes');
    }
};
