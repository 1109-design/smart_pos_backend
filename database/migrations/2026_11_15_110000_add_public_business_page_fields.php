<?php

use App\Models\Business;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public business page reached by scanning the QR on every non-fiscalised
 * sale document (fiscalised ones carry ZIMRA's verification QR instead).
 *
 * public_slug is server-owned and never changes once assigned, so a QR
 * already printed on a receipt keeps working after a rename. The profile
 * fields are entered on a till and arrive through the 'businesses' sync
 * push; website/timezone were already in that push but never stored.
 *
 * Additive only: existing rows keep every value, they just gain a slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            if (! Schema::hasColumn('businesses', 'public_slug')) {
                $table->string('public_slug', 80)->nullable()->unique();
            }
            if (! Schema::hasColumn('businesses', 'website')) {
                $table->string('website')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'timezone')) {
                $table->string('timezone', 64)->nullable();
            }
            if (! Schema::hasColumn('businesses', 'opening_hours')) {
                $table->json('opening_hours')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('businesses', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable();
            }
            if (! Schema::hasColumn('businesses', 'whatsapp')) {
                $table->string('whatsapp', 32)->nullable();
            }
            if (! Schema::hasColumn('businesses', 'facebook_url')) {
                $table->string('facebook_url')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'instagram_url')) {
                $table->string('instagram_url')->nullable();
            }
            if (! Schema::hasColumn('businesses', 'about')) {
                $table->text('about')->nullable();
            }
        });

        // Backfill: saving assigns the slug, and Business::booted() then
        // publishes a 'business_branding' record so existing tills learn
        // their public_page_url on their next pull.
        Business::query()->whereNull('public_slug')->lazyById()->each(function (Business $business) {
            $business->save();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['public_slug']);
            $table->dropColumn([
                'public_slug', 'website', 'timezone', 'opening_hours', 'latitude',
                'longitude', 'whatsapp', 'facebook_url', 'instagram_url', 'about',
            ]);
        });
    }
};
