<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductStock;

/**
 * Single place that resolves a product's final unit price — replacing the
 * previous implicit "clients just read Product.price" pattern. Didn't exist
 * before this: BackOffice quoting and the till each read `price` directly,
 * with no shared resolution for location overrides, quantity-break tiers, or
 * alternate selling units.
 */
class PricingService
{
    /**
     * Resolve the unit price for selling $quantity of $product (quantity
     * always expressed in the product's base unit, matching how stock is
     * tracked), optionally at a specific location and/or in an alternate
     * selling unit (e.g. "box").
     *
     * Resolution order: location price override (if $stock is given and has
     * one set) or the product's own price, THEN the best-matching quantity
     * tier overrides that base price entirely (tiers are deliberately not
     * layered on top of a location override — a tier price is a complete,
     * owner-configured price point of its own), THEN an alternate unit's
     * conversion factor multiplies whatever price was resolved.
     */
    public function resolveUnitPrice(
        Product $product,
        float $quantity = 1,
        ?ProductStock $stock = null,
        ?string $unitName = null,
    ): float {
        $price = $stock?->resolvedPrice() ?? (float) $product->price;

        $tier = $product->priceTiers
            ->filter(fn ($t) => (float) $t->min_qty <= $quantity)
            ->sortByDesc(fn ($t) => (float) $t->min_qty)
            ->first();

        if ($tier) {
            $price = (float) $tier->unit_price;
        }

        if ($unitName !== null) {
            $unit = $product->units->firstWhere('unit_name', $unitName);
            if ($unit && ! $unit->is_base_unit) {
                $price *= (float) $unit->conversion_factor;
            }
        }

        return $price;
    }

    /**
     * Convert a quantity expressed in an alternate selling unit (e.g. "3
     * boxes") into the product's base unit, for posting to the stock ledger.
     */
    public function toBaseUnitQuantity(Product $product, float $quantity, ?string $unitName): float
    {
        if ($unitName === null) {
            return $quantity;
        }

        $unit = $product->units->firstWhere('unit_name', $unitName);

        return $unit && ! $unit->is_base_unit ? $quantity * (float) $unit->conversion_factor : $quantity;
    }

    /**
     * Apply a manually-entered discount, but never below the product's
     * min_price floor — closes the "min_price is metadata-only" gap for
     * discounts entered through PricingService. Deliberately does NOT clamp
     * resolveUnitPrice()'s own tier/location results: an owner-configured
     * tier price below min_price is presumably intentional bulk pricing, not
     * a mistake to guard against.
     */
    public function applyManualDiscount(Product $product, float $discountedPrice): float
    {
        if ($product->min_price !== null && $discountedPrice < (float) $product->min_price) {
            return (float) $product->min_price;
        }

        return $discountedPrice;
    }
}
