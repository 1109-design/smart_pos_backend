<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per document-type print settings (one row per business per document
     * type — see App\Support\DocumentType for the registry). Composite
     * primary key, no surrogate id, same shape as the existing
     * role_permissions table.
     */
    public function up(): void
    {
        Schema::create('document_branding_settings', function (Blueprint $table) {
            $table->string('business_id');
            $table->string('document_type');
            $table->boolean('use_letterhead')->default(true);
            $table->boolean('use_footer')->default(true);
            $table->boolean('show_logo')->default(true);
            $table->string('paper_size')->nullable();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->primary(['business_id', 'document_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_branding_settings');
    }
};
