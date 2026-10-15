<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * An accounting period for one module of a business. Closed and reopened
 * on a device through approval and synced here; the server only enforces
 * it (JournalBundleIngestor rejects a journal into a closed period).
 */
class AccountingPeriod extends Model
{
    use HasUuids;

    public const MODULES = ['finance', 'sales', 'stock', 'ar', 'ap'];

    /** Source types per module; anything else is finance. */
    private const MODULE_SOURCES = [
        'sales' => ['layby_payment', 'sale', 'sale_cogs', 'sale_order_allocation', 'change_owed_claim', 'container_return', 'container_forfeit'],
        'stock' => ['grv', 'stock_adjustment', 'stock_take', 'stock_write_off', 'sheet_loss', 'product_opening_stock', 'requisition_issue', 'delivery_note', 'stock_transfer', 'grv_walk_in'],
        'ar' => ['ar_invoice', 'customer_receipt', 'customer_write_off', 'customer_adjustment', 'ar_opening_balance', 'opening_balance_customer', 'advance_allocation', 'credit_payment', 'invoice_payment', 'credit_note', 'customer_debit_note'],
        'ap' => ['payment_voucher', 'supplier_invoice', 'supplier_payment', 'supplier_credit_note'],
    ];

    protected $fillable = [
        'id', 'business_id', 'module', 'period_start', 'period_end', 'status',
        'closed_at', 'closed_by_user_id', 'closed_by_device_id',
        'reopened_at', 'reopened_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
        ];
    }

    /** The module a journal of [sourceType] belongs to. */
    public static function moduleFor(?string $sourceType): string
    {
        foreach (self::MODULE_SOURCES as $module => $sources) {
            if (in_array($sourceType, $sources, true)) {
                return $module;
            }
        }

        return 'finance';
    }

    /** Whether the finance period covering [transDate] is locked (year end). */
    public static function isLockedFor(string $businessId, string $transDate): bool
    {
        return static::where('business_id', $businessId)
            ->where('module', 'finance')
            ->whereRaw('DATE(period_start) <= ?', [$transDate])
            ->whereRaw('DATE(period_end) >= ?', [$transDate])
            ->where('status', 'locked')
            ->exists();
    }

    /**
     * Whether a journal dated [transDate] is blocked: the finance period,
     * or the given module's period, covering that date is closed or locked.
     */
    public static function isClosedFor(string $businessId, string $transDate, string $module = 'finance'): bool
    {
        return static::where('business_id', $businessId)
            ->whereIn('module', array_unique(['finance', $module]))
            ->whereRaw('DATE(period_start) <= ?', [$transDate])
            ->whereRaw('DATE(period_end) >= ?', [$transDate])
            ->whereIn('status', ['closed', 'locked'])
            ->exists();
    }
}
