<?php

use App\Models\Tenant;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Tenant::whereNull('pairing_code')->each(function (Tenant $tenant) {
            $tenant->update([
                'pairing_code' => Tenant::generateUniquePairingCode(),
            ]);
        });
    }

    public function down(): void
    {
        // Intentionally not reversible — removing pairing codes would break devices.
    }
};
