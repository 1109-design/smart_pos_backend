<?php

namespace Tests\Feature;

use App\Events\ProductPriceChanged;
use App\Events\ShiftStatusChanged;
use App\Events\StockLevelChanged;
use App\Events\TillCashMovementRecorded;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductStock;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\Till;
use App\Models\TillCashMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Proves the realtime broadcast events fire from the right model changes
 * (and only those), matching the "priority events for this milestone" list —
 * transactions/customers/etc. deliberately stay poll-only and are not
 * covered here.
 */
class RealtimeBroadcastEventsTest extends TestCase
{
    use RefreshDatabase;

    private function makeTenant(string $id): void
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => $id.'@example.com']);
    }

    public function test_stock_quantity_change_dispatches_stock_level_changed(): void
    {
        Event::fake([StockLevelChanged::class]);

        $tenantId = 'tenant-events-stock';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);
        $stock = ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 5]);

        $stock->update(['quantity' => 8]);

        Event::assertDispatched(StockLevelChanged::class, fn ($e) => $e->businessId === $tenantId
            && $e->locationId === $location->id
            && $e->productId === $product->id);
    }

    public function test_stock_reserved_quantity_change_alone_does_not_dispatch(): void
    {
        Event::fake([StockLevelChanged::class]);

        $tenantId = 'tenant-events-stock-reserved';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);
        $stock = ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 5]);

        $stock->update(['reserved_quantity' => 2]);

        Event::assertNotDispatched(StockLevelChanged::class);
    }

    public function test_product_price_change_dispatches_product_price_changed(): void
    {
        Event::fake([ProductPriceChanged::class]);

        $tenantId = 'tenant-events-price';
        $this->makeTenant($tenantId);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);

        $product->update(['price' => 12]);

        Event::assertDispatched(ProductPriceChanged::class, fn ($e) => $e->businessId === $tenantId && $e->productId === $product->id);
    }

    public function test_product_name_change_alone_does_not_dispatch(): void
    {
        Event::fake([ProductPriceChanged::class]);

        $tenantId = 'tenant-events-price-name';
        $this->makeTenant($tenantId);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Widget', 'price' => 10]);

        $product->update(['name' => 'Renamed Widget']);

        Event::assertNotDispatched(ProductPriceChanged::class);
    }

    public function test_shift_creation_dispatches_shift_status_changed(): void
    {
        Event::fake([ShiftStatusChanged::class]);

        $tenantId = 'tenant-events-shift-open';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);

        $shift = Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Event::assertDispatched(ShiftStatusChanged::class, fn ($e) => $e->shiftId === $shift->id && $e->status === 'open');
    }

    public function test_shift_closing_dispatches_shift_status_changed(): void
    {
        $tenantId = 'tenant-events-shift-close';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $shift = Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Event::fake([ShiftStatusChanged::class]);
        $shift->update(['status' => 'closed', 'closed_at' => now()]);

        Event::assertDispatched(ShiftStatusChanged::class, fn ($e) => $e->shiftId === $shift->id && $e->status === 'closed');
    }

    public function test_shift_notes_change_alone_does_not_dispatch(): void
    {
        $tenantId = 'tenant-events-shift-notes';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $shift = Shift::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'cashier_id' => (string) Str::uuid(),
            'opened_at' => now(),
            'status' => 'open',
        ]);

        Event::fake([ShiftStatusChanged::class]);
        $shift->update(['notes' => 'till was short by $2']);

        Event::assertNotDispatched(ShiftStatusChanged::class);
    }

    public function test_till_cash_movement_creation_dispatches_till_cash_movement_recorded(): void
    {
        Event::fake([TillCashMovementRecorded::class]);

        $tenantId = 'tenant-events-cash';
        $this->makeTenant($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Shop']);
        $till = Till::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'location_id' => $location->id, 'name' => 'Till 1', 'register_number' => 1]);

        $movement = TillCashMovement::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'location_id' => $location->id,
            'till_id' => $till->id,
            'type' => 'cash_in',
            'amount' => 50,
            'recorded_by_user_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(TillCashMovementRecorded::class, fn ($e) => $e->movementId === $movement->id
            && $e->tillId === $till->id
            && $e->businessId === $tenantId);
    }
}
