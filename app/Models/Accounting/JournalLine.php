<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'journal_header_id', 'gl_account_id', 'debit', 'credit',
        'currency_code', 'exchange_rate', 'foreign_debit', 'foreign_credit',
        'party_type', 'party_id', 'description',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:4',
            'credit' => 'decimal:4',
            'exchange_rate' => 'decimal:8',
            'foreign_debit' => 'decimal:4',
            'foreign_credit' => 'decimal:4',
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
     * Defense in depth beneath JournalService's own checks: once the parent
     * header leaves draft, its lines are part of a posted (or reversed)
     * journal's permanent record and must never be edited or removed
     * directly, even by code that bypasses the service.
     */
    protected static function booted(): void
    {
        static::updating(function (JournalLine $line) {
            if (! $line->header?->canEdit()) {
                return false;
            }
        });

        static::deleting(function (JournalLine $line) {
            if (! $line->header?->canEdit()) {
                return false;
            }
        });
    }
}
