<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Null (the default) means SalePostingService/GrvPostingService keep
     * posting server-side exactly as today. Once set, both stand down for
     * any transaction/receipt dated on or after this timestamp — the
     * Flutter app is expected to post its own journal for those locally
     * instead (see JournalService — the client-side port). Separate from
     * accounting_go_live_date, which only turns accounting on at all;
     * this is the second, later cutover for WHO does the posting.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->timestamp('client_gl_posting_enabled_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('client_gl_posting_enabled_at');
        });
    }
};
