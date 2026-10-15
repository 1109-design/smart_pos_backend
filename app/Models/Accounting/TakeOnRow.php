<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One row of a take-on batch, as the device validated and posted it. */
class TakeOnRow extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'batch_id', 'business_id', 'row_no', 'data', 'errors', 'status',
        'journal_id', 'ref_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'errors' => 'array',
        ];
    }
}
