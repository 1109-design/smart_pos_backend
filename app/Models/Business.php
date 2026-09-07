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
        'accounting_go_live_date',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'fiscalisation_enabled' => 'boolean',
            'stock_reset_at' => 'datetime',
            'catalogue_reset_at' => 'datetime',
            'workflow_settings' => 'array',
            'accounting_go_live_date' => 'date',
        ];
    }

    /**
     * Phase 11 accounting posting is entirely inactive until this is set —
     * see the migration for accounting_go_live_date for why.
     */
    public function accountingIsLive(): bool
    {
        return $this->accounting_go_live_date !== null;
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

    /**
     * Purchasing & Cash Vault Blueprint, part D — null (never configured,
     * the default) means no PO is ever gated, same opt-in shape as
     * workflowRequiresApproval() above.
     */
    public function poApprovalThreshold(): ?float
    {
        $value = $this->workflow_settings['po_approval_threshold'] ?? null;

        return $value !== null ? (float) $value : null;
    }

    /**
     * STC·08 — a stock-take item whose |counted - system| / system exceeds
     * this percentage gets flagged for a mandatory recount before the take
     * can be approved. Same opt-in shape as poApprovalThreshold(): null
     * (never configured, the default) means the feature is off and every
     * stock take behaves exactly as before.
     */
    public function stockTakeVarianceThresholdPercent(): ?float
    {
        $value = $this->workflow_settings['stock_take_variance_threshold_percent'] ?? null;

        return $value !== null ? (float) $value : null;
    }
}
