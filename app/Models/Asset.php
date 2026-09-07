<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Asset extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'location_id', 'created_by_user_id',
        'name', 'category', 'asset_tag',
        'purchase_date', 'purchase_cost', 'salvage_value',
        'depreciation_method', 'useful_life_years', 'depreciation_rate_percent',
        'status', 'disposed_at', 'disposal_value',
        'notes', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_cost' => 'decimal:2',
            'salvage_value' => 'decimal:2',
            'useful_life_years' => 'integer',
            'depreciation_rate_percent' => 'decimal:2',
            'disposed_at' => 'datetime',
            'disposal_value' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Current book value as of $asOf (defaults to now). Mirrors
     * smart_pos's core/assets/depreciation.dart exactly — this register
     * shows "roughly what it's worth now," computed on the fly rather than
     * stored per-period, so both sides must agree on the formula.
     *
     * @return array{book_value: float, accumulated_depreciation: float}
     */
    public function valuation(?Carbon $asOf = null): array
    {
        $cost = (float) $this->purchase_cost;
        $salvage = min(max((float) $this->salvage_value, 0), $cost);

        if ($this->status === 'disposed') {
            if ($this->disposal_value !== null) {
                $disposalValue = (float) $this->disposal_value;

                return [
                    'book_value' => $disposalValue,
                    'accumulated_depreciation' => min(max($cost - $disposalValue, 0), $cost),
                ];
            }
            if ($this->disposed_at !== null) {
                $asOf = $this->disposed_at;
            }
        }

        $asOf ??= now();
        $yearsElapsed = max(0, $this->purchase_date->diffInDays($asOf, false) / 365.25);

        return match ($this->depreciation_method) {
            'straight_line' => $this->straightLineValuation($cost, $salvage, $yearsElapsed),
            'reducing_balance' => $this->reducingBalanceValuation($cost, $salvage, $yearsElapsed),
            default => ['book_value' => $cost, 'accumulated_depreciation' => 0.0],
        };
    }

    /**
     * @return array{book_value: float, accumulated_depreciation: float}
     */
    private function straightLineValuation(float $cost, float $salvage, float $yearsElapsed): array
    {
        $life = $this->useful_life_years;
        if (! $life || $life <= 0) {
            return ['book_value' => $cost, 'accumulated_depreciation' => 0.0];
        }

        $annual = ($cost - $salvage) / $life;
        $accumulated = min(max($annual * $yearsElapsed, 0), $cost - $salvage);

        return ['book_value' => $cost - $accumulated, 'accumulated_depreciation' => $accumulated];
    }

    /**
     * @return array{book_value: float, accumulated_depreciation: float}
     */
    private function reducingBalanceValuation(float $cost, float $salvage, float $yearsElapsed): array
    {
        $ratePercent = $this->depreciation_rate_percent;
        if (! $ratePercent || $ratePercent <= 0) {
            return ['book_value' => $cost, 'accumulated_depreciation' => 0.0];
        }

        $rate = (float) $ratePercent / 100;
        $bookValue = max($cost * (1 - $rate) ** $yearsElapsed, $salvage);

        return ['book_value' => $bookValue, 'accumulated_depreciation' => $cost - $bookValue];
    }
}
