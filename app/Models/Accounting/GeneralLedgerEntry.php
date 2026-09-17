<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class GeneralLedgerEntry extends Model
{
    use HasUuids;

    protected $table = 'general_ledger';

    const UPDATED_AT = null;

    protected $fillable = [
        'id', 'business_id', 'trans_date', 'journal_header_id', 'gl_account_id',
        'debit', 'credit', 'currency_code', 'exchange_rate', 'foreign_debit',
        'foreign_credit', 'party_type', 'party_id', 'description', 'status',
        'reconciled_at', 'bank_reconciliation_id',
    ];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date',
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'foreign_debit' => 'decimal:4',
            'foreign_credit' => 'decimal:4',
            'reconciled_at' => 'datetime',
        ];
    }

    public function header(): BelongsTo
    {
        return $this->belongsTo(JournalHeader::class, 'journal_header_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class, 'gl_account_id');
    }

    /**
     * A posted general_ledger row is permanent. Correcting it means posting
     * a reversing journal (JournalService::reverse()), which is free to
     * flip this row's status to 'reversed' for display purposes; separately,
     * BankReconciliationService is free to tag/untag it as cleared via
     * reconciled_at/bank_reconciliation_id. Anything else (changing an
     * amount, deleting a row) would silently unbalance the books it's
     * supposed to be an immutable record of.
     */
    protected static function booted(): void
    {
        static::updating(function (GeneralLedgerEntry $entry) {
            $mutableFields = ['status', 'reconciled_at', 'bank_reconciliation_id'];
            if (! empty(array_diff(array_keys($entry->getDirty()), $mutableFields))) {
                throw new RuntimeException('general_ledger rows are immutable except for the status/reconciliation tags.');
            }
        });

        static::deleting(function () {
            throw new RuntimeException('general_ledger rows can never be deleted — post a reversing journal instead.');
        });
    }
}
