<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer return or exchange recorded on a till — see the Flutter app's
 * sales_return_service.dart. Immutable once synced.
 */
class SalesReturn extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'customer_id', 'return_number',
        'original_transaction_id', 'return_transaction_id',
        'exchange_transaction_id', 'outcome', 'returned_value',
        'new_items_value', 'net_amount', 'settlement_method', 'reason',
        'requested_by_user_id', 'approved_by_user_id', 'approval_request_id',
    ];

    protected function casts(): array
    {
        return [
            'returned_value' => 'decimal:4',
            'new_items_value' => 'decimal:4',
            'net_amount' => 'decimal:4',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class, 'sales_return_id');
    }
}
