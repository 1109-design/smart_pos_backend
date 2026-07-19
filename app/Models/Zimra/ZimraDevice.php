<?php

namespace App\Models\Zimra;

use Illuminate\Database\Eloquent\Model;

class ZimraDevice extends Model
{
    protected $fillable = [
        'business_id',
        'tin',
        'device_id',
        'device_serial_no',
        'activation_key',
        'is_active',
        'device_model_name',
        'device_model_version',
        'status',
        'error_message',
        'tax_codes',
        'applicable_taxes',
        'certificate_data',
        'private_key_data',
        'certificate_expires_at',
        'fiscal_day_opened_at',
        'fiscal_day_max_hours',
        'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tax_codes' => 'array',
            'applicable_taxes' => 'array',
            'certificate_expires_at' => 'datetime',
            'fiscal_day_opened_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function markAsActive(): void
    {
        $this->update([
            'status' => 'active',
            'error_message' => null,
            'last_sync_at' => now(),
        ]);
    }

    public function markAsError(string $message): void
    {
        $this->update([
            'status' => 'error',
            'error_message' => $message,
        ]);
    }
}
