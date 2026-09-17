<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tie-breaker for the pull() cursor: synced_at has only whole-second
 * precision, and a busy table (seeded data, or just a fast device) can have
 * hundreds of sync_records rows sharing one exact synced_at value. Paginating
 * on `synced_at > cursor` alone means the moment a page boundary lands
 * mid-tie, whichever tied rows didn't make it into that page are gone
 * forever — the next page's `>` (not `>=`) permanently excludes their own
 * synced_at value. sync_records.id is a plain autoincrement with no ties;
 * pairing it with synced_at as (synced_at, id) > (cursor_at, cursor_id)
 * closes the gap without changing the response shape or requiring every
 * caller to adopt a new id-only cursor.
 *
 * Originally authored against the `tenant` connection/migrations path, which
 * is dead code here (see the top-level 2026_05_05_065038 migration that
 * actually created sync_cursors) — moved to the real, single-database
 * top-level migration path this project actually runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->unsignedBigInteger('last_pulled_id')->nullable()->after('last_pulled_at');
        });
    }

    public function down(): void
    {
        Schema::table('sync_cursors', function (Blueprint $table) {
            $table->dropColumn('last_pulled_id');
        });
    }
};
