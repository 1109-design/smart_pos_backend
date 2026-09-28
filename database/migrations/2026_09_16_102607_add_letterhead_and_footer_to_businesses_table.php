<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * letterhead_path/footer_path mirror logo_path: device-opaque local
     * paths on the till, real storage paths on the server, delivered to
     * devices only via Business::publishBrandingSyncRecord() (never the
     * generic 'businesses' sync case) — see BusinessBrandingService.
     * footer_text is plain business data (bank details/terms/thank-you
     * note) and syncs bidirectionally through the generic 'businesses'
     * case like any other business column.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('letterhead_path')->nullable()->after('logo_path');
            $table->string('footer_path')->nullable()->after('letterhead_path');
            $table->text('footer_text')->nullable()->after('footer_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['letterhead_path', 'footer_path', 'footer_text']);
        });
    }
};
