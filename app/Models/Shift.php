<?php

namespace App\Models;

use App\Events\ShiftStatusChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'till_id', 'cashier_id', 'opened_at', 'closed_at', 'status',
        'opening_float', 'expected_cash', 'counted_cash', 'variance',
        'total_sales', 'cash_sales', 'card_sales', 'mobile_money_sales',
        'credit_sales', 'total_refunds', 'total_discounts', 'transaction_count',
        'opening_float_json', 'counted_cash_json', 'notes',
    ];

    protected static function booted(): void
    {
        static::created(function (Shift $shift): void {
            $shift->broadcastStatusChange();
        });

        static::updated(function (Shift $shift): void {
            if ($shift->wasChanged('status')) {
                $shift->broadcastStatusChange();
            }
        });
    }

    private function broadcastStatusChange(): void
    {
        if ($this->business_id && $this->location_id) {
            ShiftStatusChanged::dispatch($this->business_id, $this->location_id, $this->id, $this->status);
        }
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_float_json' => 'array',
            'counted_cash_json' => 'array',
        ];
    }

    public function till(): BelongsTo
    {
        return $this->belongsTo(Till::class);
    }
}
