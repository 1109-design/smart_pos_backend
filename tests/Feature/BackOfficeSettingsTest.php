<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Bundle;
use App\Models\BundleItem;
use App\Models\Business;
use App\Models\Category;
use App\Models\ContainerDepositLedger;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PoAuditLog;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\ProductStock;
use App\Models\ProductTaxRate;
use App\Models\ProductVariant;
use App\Models\ProductVariantStock;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockTake;
use App\Models\StockTakeItem;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionTax;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com',
            'is_active' => true,
        ]);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $role,
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    /**
     * Seeds real ledger-backed opening stock, the way every genuine write in
     * this app creates it — a bare `stock_quantity` column write with no
     * backing stock_movements row never happens outside a test, and would
     * make recomputeProductStock's ledger sum diverge from the fixture.
     */
    private function seedOpeningStock(string $tenantId, string $productId, float $quantity, ?string $locationId): void
    {
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId,
            'location_id' => $locationId,
            'product_id' => $productId,
            'type' => 'opening_stock',
            'quantity_change' => $quantity,
        ]);
    }

    public function test_owner_can_zero_out_stock_everywhere_in_one_shot(): void
    {
        $tenantId = 'tenant-office-settings-1';
        $user = $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main']);

        // A product with stock split across a known location and an
        // "unattributed" remainder (as if opening stock predates locations).
        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Coca Cola 500ml',
            'item_type' => 'product',
            'price' => 1.5,
            'stock_quantity' => 0,
        ]);
        $this->seedOpeningStock($tenantId, $product->id, 20, $location->id);
        $this->seedOpeningStock($tenantId, $product->id, 10, null);

        // A service must never be touched.
        $service = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Repair',
            'item_type' => 'service',
            'price' => 25,
            'stock_quantity' => 0,
        ]);

        $response = $this->post('/office/settings/reset-stock', ['confirm' => 'RESET']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertSame('0.0000', $product->fresh()->stock_quantity);
        $this->assertSame('0.0000', ProductStock::where('product_id', $product->id)->where('location_id', $location->id)->first()->quantity);
        $this->assertSame('0.0000', $service->fresh()->stock_quantity);

        // Ledger-backed, not a raw UPDATE.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity_change' => -20,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => null,
            'quantity_change' => -10,
        ]);

        $business = Business::find($tenantId);
        $this->assertNotNull($business->stock_reset_at);
        $this->assertSame($user->id, $business->stock_reset_by_user_id);
    }

    public function test_reset_cannot_run_a_second_time(): void
    {
        $tenantId = 'tenant-office-settings-2';
        $this->actingBackOfficeSession($tenantId);

        Business::create(['id' => $tenantId, 'name' => $tenantId, 'stock_reset_at' => now()->subDay(), 'stock_reset_by_user_id' => (string) Str::uuid()]);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Untouched Item',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 40,
        ]);

        $response = $this->post('/office/settings/reset-stock', ['confirm' => 'RESET']);

        $response->assertSessionHasErrors('stock_reset');
        $this->assertSame('40.0000', $product->fresh()->stock_quantity);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $product->id]);
    }

    public function test_confirmation_word_is_required(): void
    {
        $tenantId = 'tenant-office-settings-3';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Untouched Item',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 40,
        ]);

        $this->post('/office/settings/reset-stock', ['confirm' => 'reset'])->assertSessionHasErrors('confirm');
        $this->post('/office/settings/reset-stock', [])->assertSessionHasErrors('confirm');

        $this->assertSame('40.0000', $product->fresh()->stock_quantity);
        $this->assertNull(Business::find($tenantId)?->stock_reset_at);
    }

    public function test_non_owner_roles_are_forbidden(): void
    {
        $tenantId = 'tenant-office-settings-4';
        $this->actingBackOfficeSession($tenantId, 'manager');

        $this->get('/office/settings')->assertForbidden();
        $this->post('/office/settings/reset-stock', ['confirm' => 'RESET'])->assertForbidden();
    }

    public function test_reset_never_touches_another_businesses_stock(): void
    {
        $tenantId = 'tenant-office-settings-mine';
        $this->actingBackOfficeSession($tenantId);

        $mine = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Mine',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 0,
        ]);
        $this->seedOpeningStock($tenantId, $mine->id, 10, null);

        $otherTenantId = 'tenant-office-settings-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => '654321']);
        $other = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $otherTenantId,
            'name' => 'Not mine',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 0,
        ]);
        $this->seedOpeningStock($otherTenantId, $other->id, 999, null);

        $this->post('/office/settings/reset-stock', ['confirm' => 'RESET'])->assertSessionHasNoErrors();

        $this->assertSame('0.0000', $mine->fresh()->stock_quantity);
        $this->assertSame('999.0000', $other->fresh()->stock_quantity);
        $this->assertNull(Business::find($otherTenantId));
    }

    /**
     * Seeds one row in every table CatalogueResetService touches, for one
     * tenant, so a single test can assert the whole teardown at once rather
     * than one narrow table at a time.
     */
    private function seedFullCatalogue(string $tenantId): array
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main']);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Product', 'item_type' => 'product', 'price' => 1, 'track_stock' => true]);
        $container = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Container', 'item_type' => 'container', 'price' => 0, 'deposit_amount' => 0.5, 'track_stock' => true]);
        $taxRate = TaxRate::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'VAT', 'rate' => 15, 'type' => 'percentage']);

        $this->seedOpeningStock($tenantId, $product->id, 10, $location->id);
        $variant = ProductVariant::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'name' => 'Large']);
        $variantStock = ProductVariantStock::create(['id' => (string) Str::uuid(), 'variant_id' => $variant->id, 'location_id' => $location->id, 'quantity' => 5]);
        $productTaxRate = ProductTaxRate::create(['product_id' => $product->id, 'tax_rate_id' => $taxRate->id]);
        $containerLink = ProductContainerLink::create(['id' => (string) Str::uuid(), 'beverage_product_id' => $product->id, 'container_product_id' => $container->id, 'quantity_per_unit' => 1]);
        $depositLedger = ContainerDepositLedger::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'container_product_id' => $container->id, 'quantity' => 1, 'deposit_amount_per_unit' => 0.5, 'type' => 'issue', 'user_id' => (string) Str::uuid()]);

        $bundle = Bundle::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Combo']);
        $bundleItem = BundleItem::create(['id' => (string) Str::uuid(), 'bundle_id' => $bundle->id, 'product_id' => $product->id]);

        $transaction = Transaction::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'user_id' => (string) Str::uuid(), 'subtotal' => 10, 'total' => 10, 'base_currency' => 'USD']);
        $transactionItem = TransactionItem::create(['id' => (string) Str::uuid(), 'transaction_id' => $transaction->id, 'product_id' => $product->id, 'product_name' => 'Product', 'quantity' => 1, 'unit_price' => 10, 'line_total' => 10]);
        $transactionTax = TransactionTax::create(['id' => (string) Str::uuid(), 'transaction_id' => $transaction->id, 'tax_name' => 'VAT', 'rate_snapshot' => 15, 'taxable_amount' => 10, 'tax_amount' => 1.5]);
        $payment = Payment::create(['id' => (string) Str::uuid(), 'transaction_id' => $transaction->id, 'method' => 'cash', 'amount' => 10, 'currency_code' => 'USD', 'base_equivalent' => 10]);

        $po = PurchaseOrder::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'po_number' => 'PO-1', 'created_by_user_id' => (string) Str::uuid()]);
        $poItem = PurchaseOrderItem::create(['id' => (string) Str::uuid(), 'purchase_order_id' => $po->id, 'product_id' => $product->id, 'product_name' => 'Product', 'ordered_qty' => 5, 'unit_cost' => 1]);
        $poAudit = PoAuditLog::create(['po_id' => $po->id, 'user_id' => (string) Str::uuid(), 'user_name' => 'Tester', 'action' => 'created']);

        $stockTake = StockTake::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'title' => 'Count', 'created_by_user_id' => (string) Str::uuid()]);
        $stockTakeItem = StockTakeItem::create(['id' => (string) Str::uuid(), 'stock_take_id' => $stockTake->id, 'product_id' => $product->id, 'product_name' => 'Product', 'system_qty' => 10]);

        $location2 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Second']);
        $transfer = StockTransfer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'transfer_number' => 'TRF-1', 'from_location_id' => $location->id, 'to_location_id' => $location2->id, 'requested_by_user_id' => (string) Str::uuid()]);
        $transferItem = StockTransferItem::create(['id' => (string) Str::uuid(), 'stock_transfer_id' => $transfer->id, 'product_id' => $product->id, 'product_name' => 'Product', 'qty_requested' => 5]);

        return compact(
            'location', 'location2', 'product', 'container', 'taxRate', 'variant', 'variantStock', 'productTaxRate',
            'containerLink', 'depositLedger', 'bundle', 'bundleItem', 'transaction', 'transactionItem', 'transactionTax',
            'payment', 'po', 'poItem', 'poAudit', 'stockTake', 'stockTakeItem', 'transfer', 'transferItem'
        );
    }

    public function test_owner_can_delete_the_entire_catalogue_and_history_in_one_shot(): void
    {
        $tenantId = 'tenant-office-full-reset-1';
        $user = $this->actingBackOfficeSession($tenantId);
        $seed = $this->seedFullCatalogue($tenantId);

        $response = $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE EVERYTHING']);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        foreach (['product', 'container'] as $key) {
            $this->assertDatabaseMissing('products', ['id' => $seed[$key]->id]);
        }
        $this->assertDatabaseMissing('product_variants', ['id' => $seed['variant']->id]);
        $this->assertDatabaseMissing('product_variant_stock', ['id' => $seed['variantStock']->id]);
        $this->assertDatabaseMissing('product_tax_rates', ['product_id' => $seed['product']->id]);
        $this->assertDatabaseMissing('product_stock', ['product_id' => $seed['product']->id]);
        $this->assertDatabaseMissing('product_container_links', ['id' => $seed['containerLink']->id]);
        $this->assertDatabaseMissing('container_deposit_ledger', ['id' => $seed['depositLedger']->id]);
        $this->assertDatabaseMissing('bundles', ['id' => $seed['bundle']->id]);
        $this->assertDatabaseMissing('bundle_items', ['id' => $seed['bundleItem']->id]);
        $this->assertDatabaseMissing('transactions', ['id' => $seed['transaction']->id]);
        $this->assertDatabaseMissing('transaction_items', ['id' => $seed['transactionItem']->id]);
        $this->assertDatabaseMissing('transaction_taxes', ['id' => $seed['transactionTax']->id]);
        $this->assertDatabaseMissing('payments', ['id' => $seed['payment']->id]);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $seed['po']->id]);
        $this->assertDatabaseMissing('purchase_order_items', ['id' => $seed['poItem']->id]);
        $this->assertDatabaseMissing('po_audit_logs', ['id' => $seed['poAudit']->id]);
        $this->assertDatabaseMissing('stock_takes', ['id' => $seed['stockTake']->id]);
        $this->assertDatabaseMissing('stock_take_items', ['id' => $seed['stockTakeItem']->id]);
        $this->assertDatabaseMissing('stock_transfers', ['id' => $seed['transfer']->id]);
        $this->assertDatabaseMissing('stock_transfer_items', ['id' => $seed['transferItem']->id]);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $seed['product']->id]);

        // Never part of "the catalogue" — left alone.
        $this->assertDatabaseHas('locations', ['id' => $seed['location']->id]);
        $this->assertDatabaseHas('tax_rates', ['id' => $seed['taxRate']->id]);

        $business = Business::find($tenantId);
        $this->assertNotNull($business->catalogue_reset_at);
        $this->assertSame($user->id, $business->catalogue_reset_by_user_id);
    }

    public function test_full_reset_publishes_delete_sync_records_only_for_tables_a_device_actually_deletes_locally(): void
    {
        $tenantId = 'tenant-office-full-reset-2';
        $this->actingBackOfficeSession($tenantId);
        $seed = $this->seedFullCatalogue($tenantId);

        $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE EVERYTHING'])->assertSessionHasNoErrors();

        // Mutable on-device — a device actually deletes these locally, so a
        // delete sync_record is worth publishing.
        foreach ([
            'products' => $seed['product']->id,
            'transactions' => $seed['transaction']->id,
            'purchase_orders' => $seed['po']->id,
            'purchase_order_items' => $seed['poItem']->id,
            'stock_takes' => $seed['stockTake']->id,
            'stock_take_items' => $seed['stockTakeItem']->id,
            'stock_transfers' => $seed['transfer']->id,
            'stock_transfer_items' => $seed['transferItem']->id,
            'bundles' => $seed['bundle']->id,
            'bundle_items' => $seed['bundleItem']->id,
            'product_container_links' => $seed['containerLink']->id,
            'product_variants' => $seed['variant']->id,
            'product_variant_stock' => $seed['variantStock']->id,
        ] as $table => $id) {
            $this->assertDatabaseHas('sync_records', ['table_name' => $table, 'record_uuid' => $id, 'operation' => 'delete']);
        }

        // Hard-coded immutable/append-only on the till (see
        // smart_pos/lib/core/sync/sync_service.dart applyDelete()) — a
        // sync_record here would never be honoured locally, so none should
        // be generated.
        foreach (['stock_movements', 'transaction_items', 'transaction_taxes', 'payments', 'container_deposit_ledger', 'po_audit_logs'] as $table) {
            $this->assertDatabaseMissing('sync_records', ['table_name' => $table, 'operation' => 'delete']);
        }
    }

    public function test_full_reset_cannot_run_a_second_time(): void
    {
        $tenantId = 'tenant-office-full-reset-3';
        $this->actingBackOfficeSession($tenantId);

        Business::create(['id' => $tenantId, 'name' => $tenantId, 'catalogue_reset_at' => now()->subDay(), 'catalogue_reset_by_user_id' => (string) Str::uuid()]);

        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Still Here', 'item_type' => 'product', 'price' => 1]);

        $response = $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE EVERYTHING']);

        $response->assertSessionHasErrors('catalogue_reset');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }

    public function test_full_reset_confirmation_phrase_is_required_exactly(): void
    {
        $tenantId = 'tenant-office-full-reset-4';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Still Here', 'item_type' => 'product', 'price' => 1]);

        $this->post('/office/settings/reset-catalogue', ['confirm' => 'delete everything'])->assertSessionHasErrors('confirm');
        $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE'])->assertSessionHasErrors('confirm');
        $this->post('/office/settings/reset-catalogue', [])->assertSessionHasErrors('confirm');

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertNull(Business::find($tenantId)?->catalogue_reset_at);
    }

    public function test_full_reset_is_owner_only(): void
    {
        $tenantId = 'tenant-office-full-reset-5';
        $this->actingBackOfficeSession($tenantId, 'manager');

        $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE EVERYTHING'])->assertForbidden();
    }

    public function test_full_reset_never_touches_another_businesses_data(): void
    {
        $tenantId = 'tenant-office-full-reset-mine';
        $this->actingBackOfficeSession($tenantId);
        $this->seedFullCatalogue($tenantId);

        $otherTenantId = 'tenant-office-full-reset-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => 'RESETX']);
        $otherSeed = $this->seedFullCatalogue($otherTenantId);

        $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE EVERYTHING'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('products', ['id' => $otherSeed['product']->id]);
        $this->assertDatabaseHas('transactions', ['id' => $otherSeed['transaction']->id]);
        $this->assertDatabaseHas('purchase_orders', ['id' => $otherSeed['po']->id]);
        $this->assertNull(Business::find($otherTenantId)?->catalogue_reset_at);
    }

    public function test_full_reset_leaves_categories_suppliers_and_customers_untouched(): void
    {
        $tenantId = 'tenant-office-full-reset-master-data';
        $this->actingBackOfficeSession($tenantId);
        $this->seedFullCatalogue($tenantId);

        $category = Category::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Beverages']);
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Supplies']);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Jane Doe']);

        $this->post('/office/settings/reset-catalogue', ['confirm' => 'DELETE EVERYTHING'])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('categories', ['id' => $category->id]);
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
        $this->assertDatabaseHas('customers', ['id' => $customer->id]);
    }

    public function test_owner_can_turn_on_the_stock_transfer_approval_workflow(): void
    {
        $tenantId = 'tenant-office-workflow-1';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => $tenantId]);

        $this->post('/office/settings/workflows', ['stock_transfer_requires_approval' => true])
            ->assertRedirect();

        $this->assertTrue(
            Business::find($tenantId)->workflowRequiresApproval('stock_transfer_requires_approval')
        );
    }

    public function test_turning_a_workflow_off_again_does_not_disturb_other_workflow_keys(): void
    {
        $tenantId = 'tenant-office-workflow-2';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'workflow_settings' => ['some_other_key' => true]]);

        $this->post('/office/settings/workflows', ['stock_transfer_requires_approval' => true])->assertRedirect();
        $this->post('/office/settings/workflows', ['stock_transfer_requires_approval' => false])->assertRedirect();

        $business = Business::find($tenantId);
        $this->assertFalse($business->workflowRequiresApproval('stock_transfer_requires_approval'));
        $this->assertTrue($business->workflow_settings['some_other_key']);
    }

    public function test_workflow_settings_are_owner_only(): void
    {
        $tenantId = 'tenant-office-workflow-3';
        $this->actingBackOfficeSession($tenantId, 'manager');
        Business::create(['id' => $tenantId, 'name' => $tenantId]);

        $this->post('/office/settings/workflows', ['stock_transfer_requires_approval' => true])
            ->assertForbidden();
    }
}
