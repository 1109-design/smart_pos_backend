<?php

namespace App\Models;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'asset_number', 'name', 'category', 'notes',
        'acquisition_date', 'acquisition_cost', 'salvage_value', 'useful_life_months',
        'funding_method', 'status', 'disposed_at', 'disposal_proceeds', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date',
            'acquisition_cost' => 'decimal:4',
            'salvage_value' => 'decimal:4',
            'disposed_at' => 'date',
            'disposal_proceeds' => 'decimal:4',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function depreciableBase(): float
    {
        return max(0, (float) $this->acquisition_cost - (float) $this->salvage_value);
    }

    /**
     * Diminishing (reducing) balance rate, applied to the asset's current
     * net book value each period rather than a fixed straight-line amount.
     * No per-business/per-asset rate is configurable yet, so this uses the
     * conventional "200% declining balance" rate — double the equivalent
     * straight-line rate implied by useful_life_months — which is the
     * standard default reducing-balance software uses absent an explicit
     * rate. Capped at 100% so a 1—2 month useful life can't produce a
     * rate above 1.
     */
    public function monthlyDepreciationRate(): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        return min(1.0, 2 / $this->useful_life_months);
    }

    /**
     * @param  string  $businessId  needed to read the asset's current net
     *                              book value from the ledger — diminishing
     *                              balance charges a rate against whatever
     *                              is left, not a fixed dollar amount, so
     *                              (unlike the old straight-line formula)
     *                              this can no longer be computed from the
     *                              asset's own columns alone.
     */
    public function monthlyDepreciation(string $businessId): float
    {
        if ($this->useful_life_months <= 0) {
            return 0.0;
        }

        $remaining = max(0, $this->bookValue($businessId) - (float) $this->salvage_value);

        return round($remaining * $this->monthlyDepreciationRate(), 4);
    }

    /**
     * Derived from general_ledger, never stored — same principle as
     * GlAccount::balance() and PartyLedgerService, scoped to this one
     * asset via party_type/party_id (the same columns those use for a
     * customer or supplier, reused here rather than adding new ones).
     */
    public function accumulatedDepreciation(string $businessId): float
    {
        $account = GlAccount::where('business_id', $businessId)->where('code', '1510')->first();

        if (! $account) {
            return 0.0;
        }

        $totals = GeneralLedgerEntry::where('gl_account_id', $account->id)
            ->where('party_type', 'asset')
            ->where('party_id', $this->id)
            ->selectRaw('COALESCE(SUM(credit), 0) as total_credit, COALESCE(SUM(debit), 0) as total_debit')
            ->first();

        return round((float) $totals->total_credit - (float) $totals->total_debit, 4);
    }

    public function bookValue(string $businessId): float
    {
        return round((float) $this->acquisition_cost - $this->accumulatedDepreciation($businessId), 4);
    }
}
