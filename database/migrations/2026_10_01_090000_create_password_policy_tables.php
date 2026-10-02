<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Till passwords replace the 4-digit PIN (security audit). Mirrors the
 * till's raw-SQL tables in password_tables.dart:
 *
 * - user_credentials  — one row per user (user_id is the primary key and
 *   the sync uuid). Holds the bcrypt hash of the till password, whether
 *   it must be changed at next sign-in, when it was last set, and the
 *   hashes of recent passwords so they can't be reused.
 * - password_policies — the owner's rules, one row per business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_credentials', function (Blueprint $table) {
            $table->uuid('user_id')->primary();
            $table->string('business_id')->index();
            $table->string('password_hash')->nullable();
            $table->boolean('must_change')->default(false);
            $table->timestamp('password_changed_at')->nullable();
            // JSON array of earlier bcrypt hashes, newest first.
            $table->text('history_json')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('password_policies', function (Blueprint $table) {
            $table->string('business_id')->primary();
            $table->unsignedTinyInteger('min_length')->default(8);
            $table->boolean('require_uppercase')->default(true);
            $table->boolean('require_lowercase')->default(true);
            $table->boolean('require_digit')->default(true);
            $table->boolean('require_symbol')->default(false);
            // 0 = never expires.
            $table->unsignedSmallInteger('expiry_days')->default(90);
            // How many earlier passwords can't be reused (0 = no check).
            $table->unsignedTinyInteger('history_count')->default(5);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->unsignedSmallInteger('lockout_minutes')->default(15);
            // 0 = never auto-lock.
            $table->unsignedSmallInteger('idle_lock_minutes')->default(15);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_policies');
        Schema::dropIfExists('user_credentials');
    }
};
