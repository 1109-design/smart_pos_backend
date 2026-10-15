<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One take-on batch (Phase 3) — an imported file or a manual entry of one
 * type, captured, approved and posted on a device. The server stores it,
 * relays it, and accepts the batch's journals only once it is approved.
 */
class TakeOnBatch extends Model
{
    use HasUuids;

    public const TYPES = ['gl', 'customers', 'deposits', 'suppliers', 'stock', 'bank', 'assets', 'vat'];

    public const STATUSES = ['draft', 'validated', 'pending_approval', 'posting', 'posted', 'reversed'];

    /** Statuses that need an approved take_on_batch request. */
    public const APPROVED = ['posting', 'posted', 'reversed'];

    protected $fillable = [
        'id', 'business_id', 'type', 'status', 'source', 'file_name', 'file_path',
        'row_count', 'totals', 'approval_request_id', 'uploaded_by_user_id',
        'approved_by_user_id', 'posted_at', 'posted_by_device_id', 'reversed_at',
        'reversed_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(TakeOnRow::class, 'batch_id');
    }
}
