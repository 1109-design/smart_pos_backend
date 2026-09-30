<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's Pending Book rules, mirrored from the till's
 * `pending_book_settings` (pending_book_tables.dart). One row per business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pending_book_settings', function (Blueprint $table) {
            $table->string('business_id')->primary();
            // Customer signs on screen before goods are released.
            $table->boolean('require_collection_signature')->default(false);
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_book_settings');
    }
};
