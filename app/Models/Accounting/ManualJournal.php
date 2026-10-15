<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A general journal captured, approved and posted on a device (Phase 4).
 * The server stores and relays it, and accepts its ledger journal only
 * once it is approved.
 */
class ManualJournal extends Model
{
    use HasUuids;

    public const TYPES = ['general', 'accrual', 'prepayment', 'depreciation', 'payroll', 'bank', 'reclassification', 'correction'];

    public const STATUSES = ['draft', 'submitted', 'pending_approval', 'approved', 'posted', 'rejected', 'reversed'];

    /** Statuses that mean the journal was approved. */
    public const APPROVED = ['approved', 'posted', 'reversed'];

    public const LINE_TYPES = ['gl', 'customer', 'supplier', 'bank', 'cash'];

    protected $fillable = [
        'id', 'business_id', 'document_number', 'journal_type', 'trans_date', 'reference',
        'narration', 'location_id', 'currency_code', 'exchange_rate', 'status',
        'auto_reverse_date', 'corrects_journal_id', 'import_ref', 'approval_request_id',
        'created_by_user_id', 'submitted_at', 'approved_by_user_id', 'approved_at',
        'rejected_reason', 'posted_journal_id', 'reversed_by_user_id', 'reversed_at',
    ];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date',
            'auto_reverse_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'reversed_at' => 'datetime',
            'exchange_rate' => 'float',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ManualJournalLine::class)->orderBy('line_no');
    }
}
