<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A shift's Z-report (Phase 7.3), made on the device that closed the shift.
 * Its figures never change; the cash-up's approval and printing do.
 */
class ZReport extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'till_id', 'shift_id', 'cashier_id',
        'report_number', 'opened_at', 'closed_at', 'data', 'variance', 'status',
        'approval_request_id', 'approved_by_user_id', 'approved_at',
        'variance_journal_id', 'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'approved_at' => 'datetime',
            'printed_at' => 'datetime',
            'variance' => 'float',
        ];
    }
}
