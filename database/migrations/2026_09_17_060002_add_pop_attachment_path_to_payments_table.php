<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Local device path to a photo of the customer's proof-of-payment slip
     * for a bank-transfer tender. Same local-file-only limitation as
     * stock_movements.attachment_path — meaningful only to the device that
     * captured it, not yet an uploaded file the backend or other devices
     * can resolve.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('pop_attachment_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('pop_attachment_path');
        });
    }
};
