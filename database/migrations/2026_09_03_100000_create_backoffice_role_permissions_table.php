<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deliberately separate from the till app's `role_permissions` table
     * (synced from devices, keyed to a fixed 3-value Dart enum). Reusing that
     * table for BackOffice-only permissions or brand new role names would
     * corrupt the till's local state: it silently strips any permission key
     * it doesn't recognize on save, and folds any role name it doesn't
     * recognize into 'cashier'. This table is never touched by the sync
     * pipeline, so it's safe for BackOffice-only permissions and for role
     * names the till app has never heard of.
     */
    public function up(): void
    {
        Schema::create('backoffice_role_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('business_id')->index();
            $table->string('role');
            $table->json('permissions_json')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'role']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backoffice_role_permissions');
    }
};
