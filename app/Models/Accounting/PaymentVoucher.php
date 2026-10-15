<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A payment voucher (Phase 8.2), raised and paid on a device. Approved by
 * someone other than whoever raised it; once approved it only moves
 * forward (part paid, paid, closed).
 */
class PaymentVoucher extends Model
{
    use HasUuids;

    /** How far along a voucher is; it never moves back once approved. */
    public const STATUS_RANK = [
        'draft' => 0, 'pending_approval' => 0, 'rejected' => 0,
        'approved' => 1, 'part_paid' => 2, 'paid' => 3, 'closed' => 3,
    ];

    protected $fillable = [
        'id', 'business_id', 'voucher_number', 'voucher_type', 'supplier_id',
        'payee', 'voucher_date', 'description', 'total', 'paid_amount',
        'status', 'close_reason', 'lines', 'payments', 'history',
        'approval_request_id', 'accrual_journal_id', 'created_by_user_id',
        'approved_by_user_id', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'lines' => 'array',
            'payments' => 'array',
            'history' => 'array',
            'voucher_date' => 'date',
            'approved_at' => 'datetime',
            'total' => 'float',
            'paid_amount' => 'float',
        ];
    }
}
