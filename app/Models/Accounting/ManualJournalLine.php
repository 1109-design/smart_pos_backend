<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One line of a manual journal, as captured on the device. */
class ManualJournalLine extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'manual_journal_id', 'line_no', 'line_type', 'gl_account_id', 'party_id',
        'bank_account_id', 'description', 'debit', 'credit', 'currency_code',
        'exchange_rate', 'foreign_amount', 'cost_centre_id', 'location_id',
        'tax_rate_id', 'is_tax_line',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'float',
            'credit' => 'float',
            'exchange_rate' => 'float',
            'foreign_amount' => 'float',
            'is_tax_line' => 'boolean',
        ];
    }
}
