<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class ProcurementBudget extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'name', 'period_start', 'period_end',
        'amount', 'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:4',
        ];
    }

    /**
     * The budget covering a given date, if any. A business can only ever
     * have one budget active per date in practice (BackOffice doesn't stop
     * overlapping ranges being created, but the gate just picks the first
     * match) — kept simple rather than resolving overlaps, since the
     * BackOffice form is the only way to create one and can just avoid it.
     */
    public static function activeFor(string $businessId, Carbon $date): ?self
    {
        return static::where('business_id', $businessId)
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->first();
    }

    /**
     * Committed purchasing spend within this budget's period — every PO
     * actually sent to a supplier (draft/cancelled never happened as far
     * as spend is concerned; pending_approval hasn't happened YET, so it's
     * excluded too, same as draft). [$excludePoId] lets the gate check
     * "spend without this PO" before deciding whether adding it back in
     * would tip the total over.
     */
    public function spentSoFar(?string $excludePoId = null): float
    {
        return (float) PurchaseOrder::where('business_id', $this->business_id)
            ->whereIn('status', ['sent', 'partial', 'received'])
            ->whereDate('created_at', '>=', $this->period_start)
            ->whereDate('created_at', '<=', $this->period_end)
            ->when($excludePoId, fn ($q) => $q->where('id', '!=', $excludePoId))
            ->sum('total_ordered');
    }

    public function remaining(?string $excludePoId = null): float
    {
        return (float) $this->amount - $this->spentSoFar($excludePoId);
    }
}
