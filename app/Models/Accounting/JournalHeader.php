<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalHeader extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'journal_number', 'trans_date', 'description',
        'source_type', 'source_id', 'status', 'posted_at', 'posted_by_user_id',
        'reversed_by_journal_id', 'reversed_at', 'reversed_by_user_id',
        'reversal_of_journal_id',
    ];

    protected function casts(): array
    {
        return [
            'trans_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_header_id');
    }

    /**
     * Only a draft header's lines can be added to, edited, or removed —
     * once posted, correcting a mistake means reversing it, never rewriting
     * history (see JournalService::reverse()).
     */
    public function canEdit(): bool
    {
        return $this->status === 'draft';
    }
}
