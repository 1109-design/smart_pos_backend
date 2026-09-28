<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlAccount extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'code', 'name', 'account_category_id',
        'account_sub_category_id', 'allow_direct_posting', 'control_type',
        'must_be_positive', 'status',
    ];

    protected function casts(): array
    {
        return [
            'allow_direct_posting' => 'boolean',
            'must_be_positive' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AccountCategory::class, 'account_category_id');
    }

    public function subCategory(): BelongsTo
    {
        return $this->belongsTo(AccountSubCategory::class, 'account_sub_category_id');
    }

    /**
     * Current balance, derived from the posted ledger — never stored. Sign
     * follows the account's category: positive means "on its normal side."
     */
    public function balance(): float
    {
        $totals = GeneralLedgerEntry::where('gl_account_id', $this->id)
            ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
            ->first();

        $debit = (float) $totals->total_debit;
        $credit = (float) $totals->total_credit;

        return $this->category?->is_debit_normal ? $debit - $credit : $credit - $debit;
    }
}
