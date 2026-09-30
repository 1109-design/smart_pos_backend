<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On-screen signatures captured on the tills for printable documents (GRV,
 * payment voucher, requisition, delivery note, gate pass, payslip) —
 * mirrored from the Flutter app's core/signatures/document_signatures.dart.
 * A signature is never changed once captured; re-signing a line voids the
 * old row and adds a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('business_id')->index();
            $table->string('document_type');
            $table->string('document_id');
            $table->string('slot');
            $table->string('signer_name');
            $table->longText('image_png'); // base64 PNG
            $table->uuid('signed_by_user_id')->nullable();
            $table->timestamp('signed_at');
            $table->timestamp('voided_at')->nullable();
            $table->uuid('voided_by_user_id')->nullable();
            $table->timestamps();

            $table->index(['document_type', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
    }
};
