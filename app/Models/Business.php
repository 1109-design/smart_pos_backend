<?php

namespace App\Models;

use Carbon\Carbon;
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
        'bank_accounts_json',
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
        'client_gl_posting_enabled_at',
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
            'client_gl_posting_enabled_at' => 'datetime',
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
     * True once this business has been cut over to Flutter posting its own
     * journals locally for the given transaction/receipt date — at which
     * point SalePostingService/GrvPostingService stand down for anything
     * on or after that date, deferring to whatever journal the client
     * pushes up. See the client_gl_posting_enabled_at migration.
     */
    public function postsFromClientFor(string $transDate): bool
    {
        if ($this->client_gl_posting_enabled_at === null) {
            return false;
        }

        return Carbon::parse($transDate)->greaterThanOrEqualTo($this->client_gl_posting_enabled_at);
    }

    /**
     * The only two fields a device needs to know about accounting cutover
     * state, published under their own narrow 'accounting_settings' sync
     * table — deliberately NOT folded into the generic 'businesses' sync
     * payload, whose apply-on-device case overwrites every unlisted field
     * with a default (see sync_service.dart's case 'businesses' comment);
     * a payload containing only these two fields would silently wipe the
     * rest of a device's local business row.
     */
    public function publishAccountingSettingsSyncRecord(): void
    {
        SyncRecord::create([
            'business_id' => $this->id,
            'table_name' => 'accounting_settings',
            'record_uuid' => $this->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $this->id,
                'accounting_go_live_date' => $this->accounting_go_live_date?->toDateString(),
                'client_gl_posting_enabled_at' => $this->client_gl_posting_enabled_at?->toIso8601String(),
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
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
