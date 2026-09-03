<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Business extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'name',
        'address',
        'phone',
        'email',
        'tax_number',
        'tin',
        'currency_code',
        'logo_path',
        'metadata',
        'fiscalisation_enabled',
        'day_shift_start',
        'night_shift_start',
        'stock_reset_at',
        'stock_reset_by_user_id',
        'catalogue_reset_at',
        'catalogue_reset_by_user_id',
        'workflow_settings',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'fiscalisation_enabled' => 'boolean',
            'stock_reset_at' => 'datetime',
            'catalogue_reset_at' => 'datetime',
            'workflow_settings' => 'array',
        ];
    }

    /**
     * A workflow gate is opt-in: absent key (never configured) or an
     * explicit false both mean "proceed as if it's not set" — today's
     * current, gate-free behavior. Only an explicit true turns it on.
     */
    public function workflowRequiresApproval(string $key): bool
    {
        return (bool) ($this->workflow_settings[$key] ?? false);
    }
}
