<?php

namespace App\Models;

use App\Events\PurchaseOrderChanged;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'receiving_location_id', 'supplier_id', 'supplier_name',
        'po_number', 'status', 'total_ordered', 'total_received', 'notes',
        'expected_date', 'additional_costs_json', 'created_by_user_id',
    ];

    protected static function booted(): void
    {
        static::created(function (PurchaseOrder $po): void {
            if (! $po->business_id) {
                return;
            }

            PurchaseOrderChanged::dispatch($po->business_id, $po->receiving_location_id, $po->id);
        });

        static::updated(function (PurchaseOrder $po): void {
            if (! $po->wasChanged(['status', 'total_received']) || ! $po->business_id) {
                return;
            }

            PurchaseOrderChanged::dispatch($po->business_id, $po->receiving_location_id, $po->id);
        });
    }

    protected function casts(): array
    {
        return [
            'total_ordered' => 'decimal:4',
            'total_received' => 'decimal:4',
            'expected_date' => 'datetime',
            'additional_costs_json' => 'array',
        ];
    }

    public function receivingLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'receiving_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function variances(): HasMany
    {
        return $this->hasMany(PoReceiptVariance::class);
    }

    /**
     * A PO is genuinely multi-device — created/sent on one till, received
     * via GRV on another (often a warehouse till) — same shape as
     * StockTransfer. SyncProcessor::gatePurchaseOrderStatus() already locks
     * 'pending_approval' against being overwritten, but for every other
     * status it applied the incoming payload unconditionally: a device that
     * sent a PO and went offline could resync its own stale 'sent' snapshot
     * after another device already received (or cancelled) it, silently
     * regressing the status. 'pending_approval' is deliberately left out of
     * ALLOWED_TRANSITIONS here since gatePurchaseOrderStatus() already
     * short-circuits that case before this is ever consulted — see its doc
     * comment on why resolution bypasses both entirely.
     */
    public const TERMINAL_STATUSES = ['received', 'cancelled'];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'draft' => ['sent', 'pending_approval', 'cancelled'],
        'sent' => ['partial', 'received', 'cancelled'],
        'partial' => ['partial', 'received'],
    ];

    public static function isValidTransition(?string $from, string $to): bool
    {
        if ($from === null || $from === $to) {
            return true;
        }

        if (in_array($from, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }
}
