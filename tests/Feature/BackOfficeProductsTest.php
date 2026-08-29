<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Category;
use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductContainerLink;
use App\Models\ProductStock;
use App\Models\StockMovement;
use App\Models\SyncCursor;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeProductsTest extends TestCase
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

    public function test_portal_created_product_gets_opening_stock_ledger_and_sync_record(): void
    {
        $tenantId = 'tenant-office-prod-1';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/products', [
            'name' => 'Portal Cola 500ml',
            'item_type' => 'product',
            'price' => 1.50,
            'cost_price' => 0.90,
            'stock_quantity' => 24,
            'track_stock' => true,
        ]);

        $response->assertRedirect();

        $product = Product::where('name', 'Portal Cola 500ml')->first();
        $this->assertNotNull($product);
        $this->assertSame('product', $product->item_type);

        // Opening stock must hit the ledger, not just the flat column.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'opening_stock',
            'quantity_change' => 24,
        ]);

        // And be published to the sync stream for every device (device_id null).
        $this->assertDatabaseHas('sync_records', [
            'table_name' => 'products',
            'record_uuid' => $product->id,
            'device_id' => null,
        ]);
    }

    public function test_opening_stock_is_attributed_to_the_chosen_location(): void
    {
        $tenantId = 'tenant-office-prod-loc-1';
        $this->actingBackOfficeSession($tenantId);

        // Visiting the index seeds the default "Main" location, mirroring the
        // real page flow the create form's default location_id comes from.
        $this->get('/office/products');
        $mainLocation = Location::where('business_id', $tenantId)->firstOrFail();

        $secondLocation = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Branch',
            'type' => 'shop',
            'is_active' => true,
        ]);

        $response = $this->post('/office/products', [
            'name' => 'Located Cola 500ml',
            'item_type' => 'product',
            'price' => 1.50,
            'stock_quantity' => 12,
            'track_stock' => true,
            'location_id' => $secondLocation->id,
        ]);
        $response->assertRedirect();

        $product = Product::where('name', 'Located Cola 500ml')->firstOrFail();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'type' => 'opening_stock',
            'location_id' => $secondLocation->id,
            'quantity_change' => 12,
        ]);
        $this->assertDatabaseHas('product_stock', [
            'product_id' => $product->id,
            'location_id' => $secondLocation->id,
            'quantity' => 12,
        ]);
        $this->assertDatabaseMissing('product_stock', [
            'product_id' => $product->id,
            'location_id' => $mainLocation->id,
        ]);

        // Flat total stays the single source of truth for the Products list.
        $this->assertSame('12.0000', $product->fresh()->stock_quantity);
    }

    public function test_portal_created_service_has_no_stock_or_ledger_entry(): void
    {
        $tenantId = 'tenant-office-prod-2';
        $this->actingBackOfficeSession($tenantId);

        $response = $this->post('/office/products', [
            'name' => 'Phone Screen Repair',
            'item_type' => 'service',
            'price' => 25,
            // Client-sent stock values must be ignored for services.
            'stock_quantity' => 99,
            'track_stock' => true,
        ]);

        $response->assertRedirect();

        $product = Product::where('name', 'Phone Screen Repair')->first();
        $this->assertNotNull($product);
        $this->assertSame('service', $product->item_type);
        $this->assertFalse((bool) $product->track_stock);
        $this->assertSame(0.0, (float) $product->stock_quantity);

        $this->assertDatabaseMissing('stock_movements', [
            'product_id' => $product->id,
        ]);
    }

    public function test_archiving_preserves_full_product_payload_in_sync_record(): void
    {
        $tenantId = 'tenant-office-prod-3';
        $this->actingBackOfficeSession($tenantId);

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Keep My Name',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 10,
        ]);

        $response = $this->patch("/office/products/{$productId}/toggle-active");

        $response->assertRedirect();

        // The product row keeps its identity and only flips is_active — the
        // sync payload must carry the full record so device pulls don't blank
        // out name/price.
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'name' => 'Keep My Name',
            'is_active' => false,
        ]);

        $syncRecord = SyncRecord::where('record_uuid', $productId)->latest('id')->first();
        $this->assertNotNull($syncRecord);
        $this->assertSame('Keep My Name', $syncRecord->payload['name']);
        $this->assertFalse((bool) $syncRecord->payload['is_active']);
    }

    public function test_toggle_active_preserves_deposit_amount_and_expiry_date(): void
    {
        $tenantId = 'tenant-office-prod-toggle-deposit';
        $this->actingBackOfficeSession($tenantId);

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Quart Bottle',
            'item_type' => 'container',
            'price' => 0,
            'deposit_amount' => 1.5,
            'expiry_date' => '2027-01-01',
        ]);

        $this->patch("/office/products/{$productId}/toggle-active")->assertRedirect();

        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'deposit_amount' => 1.5,
            'is_active' => false,
        ]);
        $this->assertNotNull(Product::find($productId)->expiry_date);
    }

    /**
     * The edit form only ever sends the fields it exposes — min_price,
     * discount_percent, deposit_amount and expiry_date aren't among them.
     * Regression test for the bug where updating just the price wiped those
     * four columns to null (both here and on every device that later synced
     * the change), because the sync pipeline treats every 'products' record
     * as a full-row overwrite.
     */
    public function test_update_preserves_fields_the_edit_form_does_not_send(): void
    {
        $tenantId = 'tenant-office-prod-preserve';
        $this->actingBackOfficeSession($tenantId);

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Coca Cola 500ml',
            'item_type' => 'product',
            'price' => 1.50,
            'min_price' => 1.00,
            'discount_percent' => 5,
            'expiry_date' => '2027-06-01',
            'stock_quantity' => 20,
        ]);

        // A minimal edit — the way the BackOffice form actually submits,
        // with no min_price/discount_percent/expiry_date fields at all.
        $response = $this->put("/office/products/{$productId}", [
            'name' => 'Coca Cola 500ml',
            'item_type' => 'product',
            'price' => 1.75,
            'stock_quantity' => 20,
        ]);

        $response->assertRedirect();

        $product = Product::find($productId);
        $this->assertSame('1.7500', $product->price);
        $this->assertSame('1.0000', $product->min_price);
        $this->assertSame('5.00', $product->discount_percent);
        $this->assertNotNull($product->expiry_date);

        $syncRecord = SyncRecord::where('record_uuid', $productId)->latest('id')->first();
        $this->assertEquals(1.0, $syncRecord->payload['min_price']);
        $this->assertEquals(5, $syncRecord->payload['discount_percent']);
    }

    public function test_container_product_can_be_created_with_a_deposit_and_linked_from_a_beverage(): void
    {
        $tenantId = 'tenant-office-prod-container';
        $this->actingBackOfficeSession($tenantId);

        $containerResponse = $this->post('/office/products', [
            'name' => 'Quart Bottle',
            'item_type' => 'container',
            'deposit_amount' => 0.5,
            'track_stock' => true,
            'stock_quantity' => 100,
        ]);
        $containerResponse->assertRedirect();

        $container = Product::where('business_id', $tenantId)->where('item_type', 'container')->firstOrFail();
        $this->assertSame('0.5000', $container->deposit_amount);
        $this->assertSame('0.0000', $container->price);

        $beverageResponse = $this->post('/office/products', [
            'name' => 'Delta Lager Quart',
            'item_type' => 'product',
            'price' => 1.20,
            'stock_quantity' => 0,
            'container_links' => [
                ['container_product_id' => $container->id, 'quantity_per_unit' => 1],
            ],
        ]);
        $beverageResponse->assertRedirect();

        $beverage = Product::where('business_id', $tenantId)->where('name', 'Delta Lager Quart')->firstOrFail();

        $this->assertDatabaseHas('product_container_links', [
            'beverage_product_id' => $beverage->id,
            'container_product_id' => $container->id,
        ]);
        $linkSync = SyncRecord::where('table_name', 'product_container_links')
            ->where('operation', 'upsert')
            ->latest('id')->first();
        $this->assertNotNull($linkSync);
        $this->assertSame($beverage->id, $linkSync->payload['beverage_product_id']);
    }

    public function test_updating_container_links_replaces_them_and_publishes_a_delete_for_the_removed_one(): void
    {
        $tenantId = 'tenant-office-prod-container-update';
        $this->actingBackOfficeSession($tenantId);

        $bottle = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Bottle', 'item_type' => 'container', 'price' => 0, 'deposit_amount' => 0.3]);
        $crate = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Crate', 'item_type' => 'container', 'price' => 0, 'deposit_amount' => 5]);

        $beverage = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Lager', 'item_type' => 'product', 'price' => 1.2]);
        ProductContainerLink::create([
            'id' => (string) Str::uuid(),
            'beverage_product_id' => $beverage->id,
            'container_product_id' => $bottle->id,
            'quantity_per_unit' => 1,
        ]);

        // Swap the bottle link for a crate link.
        $response = $this->put("/office/products/{$beverage->id}", [
            'name' => 'Lager',
            'item_type' => 'product',
            'price' => 1.2,
            'container_links' => [
                ['container_product_id' => $crate->id, 'quantity_per_unit' => 12],
            ],
        ]);
        $response->assertRedirect();

        $this->assertDatabaseMissing('product_container_links', [
            'beverage_product_id' => $beverage->id,
            'container_product_id' => $bottle->id,
        ]);
        $this->assertDatabaseHas('product_container_links', [
            'beverage_product_id' => $beverage->id,
            'container_product_id' => $crate->id,
            'quantity_per_unit' => 12,
        ]);

        $deleteSync = SyncRecord::where('table_name', 'product_container_links')
            ->where('operation', 'delete')
            ->latest('id')->first();
        $this->assertNotNull($deleteSync);
    }

    public function test_container_links_reject_the_same_container_linked_twice(): void
    {
        // Regression: without a distinct rule, linking the same container
        // twice creates two product_container_links rows for one beverage,
        // doubling the deposit charged whenever it's sold.
        $tenantId = 'tenant-office-prod-container-dup';
        $this->actingBackOfficeSession($tenantId);

        $container = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Bottle', 'item_type' => 'container', 'price' => 0, 'deposit_amount' => 0.3]);

        $response = $this->post('/office/products', [
            'name' => 'Lager',
            'item_type' => 'product',
            'price' => 1.2,
            'container_links' => [
                ['container_product_id' => $container->id, 'quantity_per_unit' => 1],
                ['container_product_id' => $container->id, 'quantity_per_unit' => 1],
            ],
        ]);

        $response->assertSessionHasErrors('container_links.0.container_product_id');
        $this->assertDatabaseMissing('products', ['business_id' => $tenantId, 'name' => 'Lager']);
    }

    public function test_reimporting_a_former_container_as_a_product_clears_its_deposit_amount(): void
    {
        // Regression: CSV import can only ever set item_type to product or
        // service, but a row matched by SKU/barcode could previously be an
        // existing container — carrying its deposit_amount forward left the
        // row with a non-null deposit on a non-container item, bypassing the
        // rule validatePayload() enforces on the manual edit form.
        $tenantId = 'tenant-office-prod-reimport-container';
        $this->actingBackOfficeSession($tenantId);

        $existingId = (string) Str::uuid();
        Product::create([
            'id' => $existingId,
            'business_id' => $tenantId,
            'name' => 'Quart Bottle',
            'item_type' => 'container',
            'price' => 0,
            'deposit_amount' => 1.5,
            'sku' => 'BOTTLE1',
        ]);

        $csv = "name,item_type,price,sku\n"
            ."Quart Bottle (Retired),product,2.00,BOTTLE1\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/office/products/import', ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionMissing('import_errors');
        $this->assertDatabaseHas('products', [
            'id' => $existingId,
            'item_type' => 'product',
            'deposit_amount' => null,
        ]);
    }

    public function test_index_reports_how_many_of_the_business_devices_have_synced_each_product(): void
    {
        $tenantId = 'tenant-office-prod-4';
        $this->actingBackOfficeSession($tenantId);

        $caughtUpDevice = Device::create(['tenant_id' => $tenantId, 'name' => 'Till 1', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now()]);
        $behindDevice = Device::create(['tenant_id' => $tenantId, 'name' => 'Till 2', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now()]);
        Device::create(['tenant_id' => $tenantId, 'name' => 'Revoked Phone', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now(), 'is_revoked' => true]);
        Device::create(['tenant_id' => $tenantId, 'name' => 'Abandoned Till', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now()->subDays(30)]);

        $productId = (string) Str::uuid();
        Product::create([
            'id' => $productId,
            'business_id' => $tenantId,
            'name' => 'Synced Item',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 10,
        ]);
        $syncedAt = now();
        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'products',
            'record_uuid' => $productId,
            'operation' => 'upsert',
            'payload' => ['name' => 'Synced Item'],
            'source_updated_at' => $syncedAt,
            'synced_at' => $syncedAt,
        ]);

        // Only one active device has pulled products since this record went out.
        SyncCursor::create(['device_id' => $caughtUpDevice->id, 'table_name' => 'products', 'last_pulled_at' => $syncedAt->clone()->addMinute()]);
        SyncCursor::create(['device_id' => $behindDevice->id, 'table_name' => 'products', 'last_pulled_at' => $syncedAt->clone()->subMinute()]);

        $unsyncedProductId = (string) Str::uuid();
        Product::create([
            'id' => $unsyncedProductId,
            'business_id' => $tenantId,
            'name' => 'Brand New Item',
            'item_type' => 'product',
            'price' => 5,
            'stock_quantity' => 10,
        ]);
        // No sync_records row at all for this one — unknown status.

        $response = $this->get('/office/products');

        $response->assertOk();
        $products = collect($response->viewData('page')['props']['products']['data']);

        $synced = $products->firstWhere('name', 'Synced Item');
        $this->assertSame(1, $synced['synced_devices']);
        $this->assertSame(2, $synced['total_devices']); // revoked device and long-idle device excluded

        $unsynced = $products->firstWhere('name', 'Brand New Item');
        $this->assertNull($unsynced['synced_devices']);
    }

    public function test_long_idle_devices_do_not_drag_down_the_sync_count(): void
    {
        // Regression: a business with several old/abandoned devices on record,
        // and exactly one phone actually in use, must not show every new item
        // as "0/4 synced" just because the abandoned ones will never pull again.
        $tenantId = 'tenant-office-prod-idle';
        $this->actingBackOfficeSession($tenantId);

        Device::create(['tenant_id' => $tenantId, 'name' => 'Old Till A', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now()->subMonths(3)]);
        Device::create(['tenant_id' => $tenantId, 'name' => 'Old Till B', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now()->subMonth()]);
        Device::create(['tenant_id' => $tenantId, 'name' => 'Never Opened', 'device_identifier' => (string) Str::uuid()]); // last_seen_at null
        $activeDevice = Device::create(['tenant_id' => $tenantId, 'name' => 'My Phone', 'device_identifier' => (string) Str::uuid(), 'last_seen_at' => now()]);

        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Just Added', 'item_type' => 'product', 'price' => 5]);
        SyncRecord::create([
            'business_id' => $tenantId,
            'table_name' => 'products',
            'record_uuid' => $productId,
            'operation' => 'upsert',
            'payload' => ['name' => 'Just Added'],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
        SyncCursor::create(['device_id' => $activeDevice->id, 'table_name' => 'products', 'last_pulled_at' => now()->addMinute()]);

        $response = $this->get('/office/products');

        $row = collect($response->viewData('page')['props']['products']['data'])->firstWhere('name', 'Just Added');

        $this->assertSame(1, $row['total_devices']); // only "My Phone" counts
        $this->assertSame(1, $row['synced_devices']);
    }

    public function test_index_only_lists_products_belonging_to_the_current_tenant(): void
    {
        $otherTenantId = 'tenant-office-prod-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        Product::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Other Business Item', 'item_type' => 'product', 'price' => 1]);

        $tenantId = 'tenant-office-prod-5';
        $this->actingBackOfficeSession($tenantId);
        Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'My Item', 'item_type' => 'product', 'price' => 1]);

        $response = $this->get('/office/products');

        $response->assertOk();
        $names = collect($response->viewData('page')['props']['products']['data'])->pluck('name');

        $this->assertContains('My Item', $names);
        $this->assertNotContains('Other Business Item', $names);
    }

    public function test_update_and_toggle_active_cannot_touch_another_tenants_product(): void
    {
        $otherTenantId = 'tenant-office-prod-other2';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignProductId = (string) Str::uuid();
        Product::create(['id' => $foreignProductId, 'business_id' => $otherTenantId, 'name' => 'Not Yours', 'item_type' => 'product', 'price' => 1]);

        $this->actingBackOfficeSession('tenant-office-prod-6');

        $this->put("/office/products/{$foreignProductId}", ['name' => 'Hijacked', 'item_type' => 'product', 'price' => 1])
            ->assertNotFound();

        $this->patch("/office/products/{$foreignProductId}/toggle-active")
            ->assertNotFound();

        $this->assertDatabaseHas('products', ['id' => $foreignProductId, 'name' => 'Not Yours', 'is_active' => true]);
    }

    public function test_import_creates_and_updates_products_from_csv(): void
    {
        $tenantId = 'tenant-office-prod-7';
        $this->actingBackOfficeSession($tenantId);

        $existingId = (string) Str::uuid();
        Product::create([
            'id' => $existingId,
            'business_id' => $tenantId,
            'name' => 'Old Name',
            'item_type' => 'product',
            'price' => 1,
            'sku' => 'EXIST1',
            'stock_quantity' => 42,
        ]);
        Category::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Beverages']);

        $csv = "name,item_type,price,cost_price,sku,barcode,category,unit,track_stock,stock_quantity,low_stock_threshold\n"
            ."Updated Name,product,2.50,1.00,EXIST1,,Beverages,piece,yes,999,5\n"
            ."Brand New Soda,product,3.00,1.50,NEWSKU,,Beverages,piece,yes,20,5\n";

        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/office/products/import', ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $response->assertSessionMissing('import_errors');

        $this->assertDatabaseHas('products', ['id' => $existingId, 'name' => 'Updated Name', 'sku' => 'EXIST1']);
        // Stock is ledger-owned, but for an existing item the flat stock_quantity column is still
        // reconciled against the default location — same as the "stock: <location>" columns are for
        // a multi-location business — so re-importing with an edited quantity actually changes it.
        $this->assertDatabaseHas('products', ['id' => $existingId, 'stock_quantity' => 999]);

        $newProduct = Product::where('sku', 'NEWSKU')->first();
        $this->assertNotNull($newProduct);
        $this->assertSame('Brand New Soda', $newProduct->name);
        $this->assertSame(20.0, (float) $newProduct->stock_quantity);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'products', 'record_uuid' => $newProduct->id]);
    }

    public function test_import_reports_row_errors_without_dropping_the_whole_batch(): void
    {
        $tenantId = 'tenant-office-prod-8';
        $this->actingBackOfficeSession($tenantId);

        $csv = "name,item_type,price\n"
            .",product,5\n" // missing name -> invalid
            ."Valid Item,product,5\n";

        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/office/products/import', ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors');

        $this->assertDatabaseHas('products', ['name' => 'Valid Item']);
        $this->assertDatabaseMissing('products', ['price' => 5, 'name' => '']);
    }

    public function test_import_rejects_file_missing_required_columns(): void
    {
        $this->actingBackOfficeSession('tenant-office-prod-9');

        $file = UploadedFile::fake()->createWithContent('import.csv', "name,price\nThing,5\n");

        $this->post('/office/products/import', ['file' => $file])
            ->assertSessionHasErrors('file');
    }

    public function test_index_shows_stock_split_by_location_when_business_has_more_than_one(): void
    {
        $tenantId = 'tenant-office-prod-locations';
        $this->actingBackOfficeSession($tenantId);

        $downtown = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Downtown']);
        $mall = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Mall']);

        $this->post('/office/products', [
            'name' => 'Split Stock Item',
            'item_type' => 'product',
            'price' => 2,
            'stock_quantity' => 10,
            'track_stock' => true,
            'location_id' => $downtown->id,
        ])->assertRedirect();

        $product = Product::where('name', 'Split Stock Item')->firstOrFail();

        // A second opening batch at the Mall branch — same product, seeded
        // from that till's own "Stock By Location" field, which pushes its
        // own opening_stock movement (see product_form_screen.dart).
        app(SyncProcessor::class)->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId,
            'location_id' => $mall->id,
            'product_id' => $product->id,
            'type' => 'opening_stock',
            'quantity_change' => 6,
        ]);

        $response = $this->get('/office/products');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('products.data.0.name', 'Split Stock Item')
            ->has('products.data.0.stock_by_location', 2)
        );
    }

    public function test_index_omits_stock_split_for_single_location_businesses(): void
    {
        $tenantId = 'tenant-office-prod-single-location';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/products', [
            'name' => 'Single Location Item',
            'item_type' => 'product',
            'price' => 2,
            'stock_quantity' => 10,
            'track_stock' => true,
        ])->assertRedirect();

        $response = $this->get('/office/products');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('products.data.0.name', 'Single Location Item')
            ->missing('products.data.0.stock_by_location')
        );
    }

    public function test_setting_opening_balance_records_the_delta_against_live_stock(): void
    {
        $tenantId = 'tenant-office-prod-balance-1';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);

        $this->post('/office/products', [
            'name' => 'Take-On Item',
            'item_type' => 'product',
            'price' => 2,
            'stock_quantity' => 10,
            'track_stock' => true,
            'location_id' => $location->id,
        ])->assertRedirect();

        $product = Product::where('name', 'Take-On Item')->firstOrFail();

        $response = $this->post("/office/products/{$product->id}/opening-balance", [
            'location_id' => $location->id,
            'quantity' => 50,
        ]);
        $response->assertRedirect();

        // Delta of +40 posted, not a raw overwrite — keeps the ledger intact.
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => 'opening_stock',
            'quantity_change' => 40,
        ]);
        $this->assertDatabaseHas('product_stock', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'quantity' => 50,
        ]);
        $this->assertSame('50.0000', $product->fresh()->stock_quantity);

        $syncRecord = SyncRecord::where('table_name', 'stock_movements')
            ->where('device_id', null)
            ->latest('id')->first();
        $this->assertNotNull($syncRecord);
        $this->assertSame(40.0, (float) $syncRecord->payload['quantity_change']);
    }

    public function test_setting_opening_balance_twice_to_the_same_number_is_a_no_op_the_second_time(): void
    {
        $tenantId = 'tenant-office-prod-balance-2';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);

        $this->post('/office/products', [
            'name' => 'Repeat Take-On Item',
            'item_type' => 'product',
            'price' => 2,
            'stock_quantity' => 0,
            'track_stock' => true,
            'location_id' => $location->id,
        ])->assertRedirect();
        $product = Product::where('name', 'Repeat Take-On Item')->firstOrFail();

        $this->post("/office/products/{$product->id}/opening-balance", [
            'location_id' => $location->id,
            'quantity' => 30,
        ])->assertRedirect();

        $movementCountBefore = StockMovement::where('product_id', $product->id)->count();

        // Re-submitting the same exact balance must not stack another movement.
        $this->post("/office/products/{$product->id}/opening-balance", [
            'location_id' => $location->id,
            'quantity' => 30,
        ])->assertRedirect();

        $this->assertSame($movementCountBefore, StockMovement::where('product_id', $product->id)->count());
        $this->assertSame('30.0000', $product->fresh()->stock_quantity);
    }

    public function test_opening_balance_rejects_a_location_from_another_tenant(): void
    {
        $otherTenantId = 'tenant-office-prod-balance-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Warehouse']);

        $tenantId = 'tenant-office-prod-balance-3';
        $this->actingBackOfficeSession($tenantId);

        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Guarded Item', 'item_type' => 'product', 'price' => 1, 'track_stock' => true]);

        $this->post("/office/products/{$productId}/opening-balance", [
            'location_id' => $foreignLocation->id,
            'quantity' => 10,
        ])->assertSessionHasErrors('location_id');
    }

    public function test_receive_stock_adds_quantity_and_recomputes_weighted_average_cost(): void
    {
        $tenantId = 'tenant-office-prod-receive-1';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Receivable Item',
            'item_type' => 'product',
            'price' => 5,
            'cost_price' => 2,
            'track_stock' => true,
            'stock_quantity' => 10,
        ]);
        // A real ledger row for the starting balance — stock_quantity is
        // ledger-owned, so a bare Eloquent field without a matching
        // stock_movements row would get overwritten by the receipt's own
        // recompute below.
        StockMovement::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'product_id' => $product->id,
            'location_id' => $location->id, 'type' => 'opening_stock', 'quantity_change' => 10,
        ]);
        ProductStock::create(['id' => (string) Str::uuid(), 'product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 10, 'reserved_quantity' => 0]);

        // 10 units already on hand @ $2, receiving 10 more @ $4 → WAC of $3.
        $response = $this->post('/office/products/receive-stock', [
            'location_id' => $location->id,
            'reason' => 'PO-100 delivery',
            'items' => [
                ['product_id' => $product->id, 'qty' => 10, 'unit_cost' => 4],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'location_id' => $location->id,
            'type' => 'receive',
            'quantity_change' => 10,
            'unit_cost' => 4,
            'running_avg_cost' => 3,
            'reason' => 'PO-100 delivery',
        ]);

        $fresh = $product->fresh();
        $this->assertSame('3.0000', $fresh->cost_price);
        $this->assertSame('20.0000', $fresh->stock_quantity);
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $location->id, 'quantity' => 20]);
    }

    public function test_receive_stock_handles_multiple_items_in_one_receipt(): void
    {
        $tenantId = 'tenant-office-prod-receive-2';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $productA = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Item A', 'item_type' => 'product', 'price' => 1, 'track_stock' => true]);
        $productB = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Item B', 'item_type' => 'product', 'price' => 1, 'track_stock' => true]);

        $response = $this->post('/office/products/receive-stock', [
            'location_id' => $location->id,
            'items' => [
                ['product_id' => $productA->id, 'qty' => 5, 'unit_cost' => 1],
                ['product_id' => $productB->id, 'qty' => 8, 'unit_cost' => 2],
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame('5.0000', $productA->fresh()->stock_quantity);
        $this->assertSame('8.0000', $productB->fresh()->stock_quantity);
    }

    public function test_receive_stock_rejects_a_location_from_another_tenant(): void
    {
        $otherTenantId = 'tenant-office-prod-receive-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Warehouse']);

        $tenantId = 'tenant-office-prod-receive-3';
        $this->actingBackOfficeSession($tenantId);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Guarded Receive Item', 'item_type' => 'product', 'price' => 1, 'track_stock' => true]);

        $this->post('/office/products/receive-stock', [
            'location_id' => $foreignLocation->id,
            'items' => [
                ['product_id' => $product->id, 'qty' => 5, 'unit_cost' => 1],
            ],
        ])->assertSessionHasErrors('location_id');

        $this->assertSame('0.0000', $product->fresh()->stock_quantity);
    }

    public function test_receive_stock_cannot_touch_another_tenants_product(): void
    {
        $otherTenantId = 'tenant-office-prod-receive-foreign-product';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignProduct = Product::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Not Yours', 'item_type' => 'product', 'price' => 1, 'track_stock' => true]);

        $tenantId = 'tenant-office-prod-receive-4';
        $this->actingBackOfficeSession($tenantId);
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);

        $response = $this->post('/office/products/receive-stock', [
            'location_id' => $location->id,
            'items' => [
                ['product_id' => $foreignProduct->id, 'qty' => 5, 'unit_cost' => 1],
            ],
        ]);

        // Silently skipped rather than a hard error — matching the CSV
        // import's per-row tolerance — but nothing about the foreign
        // product changes, and the response reports zero items received.
        $response->assertSessionHasErrors('items');
        $this->assertSame('0.0000', $foreignProduct->fresh()->stock_quantity);
    }

    public function test_receive_stock_template_lists_active_tracked_products_with_current_cost(): void
    {
        $tenantId = 'tenant-office-prod-receive-template';
        $this->actingBackOfficeSession($tenantId);

        Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Templated Item', 'item_type' => 'product', 'price' => 1, 'cost_price' => 2.5, 'sku' => 'TPL1', 'track_stock' => true]);
        Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Untracked Service', 'item_type' => 'service', 'price' => 1, 'track_stock' => false]);
        Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Archived Item', 'item_type' => 'product', 'price' => 1, 'track_stock' => true, 'is_active' => false]);

        $response = $this->get('/office/products/receive-stock/template');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith('sku,barcode,name,qty,unit_cost', $content);
        $this->assertStringContainsString('TPL1,,"Templated Item",,2.5000', $content);
        $this->assertStringNotContainsString('Untracked Service', $content);
        $this->assertStringNotContainsString('Archived Item', $content);
    }

    public function test_receive_stock_import_matches_by_sku_then_barcode_and_skips_blank_qty_rows(): void
    {
        $tenantId = 'tenant-office-prod-receive-import-1';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $bySku = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Matched By SKU', 'item_type' => 'product', 'price' => 1, 'sku' => 'BYSKU', 'track_stock' => true]);
        $byBarcode = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Matched By Barcode', 'item_type' => 'product', 'price' => 1, 'barcode' => 'BYBAR123', 'track_stock' => true]);
        $skipped = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Left Blank', 'item_type' => 'product', 'price' => 1, 'sku' => 'BLANK1', 'track_stock' => true]);

        $csv = "sku,barcode,name,qty,unit_cost\n"
            ."BYSKU,,Matched By SKU,10,3\n"
            .",BYBAR123,Matched By Barcode,4,1.5\n"
            ."BLANK1,,Left Blank,,5\n";
        $file = UploadedFile::fake()->createWithContent('receive.csv', $csv);

        $response = $this->post('/office/products/receive-stock/import', [
            'file' => $file,
            'location_id' => $location->id,
            'reason' => 'Weekly delivery',
        ]);

        $response->assertRedirect();
        $response->assertSessionMissing('import_errors');

        $this->assertSame('10.0000', $bySku->fresh()->stock_quantity);
        $this->assertSame('4.0000', $byBarcode->fresh()->stock_quantity);
        $this->assertSame('0.0000', $skipped->fresh()->stock_quantity);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $bySku->id, 'location_id' => $location->id, 'type' => 'receive',
            'quantity_change' => 10, 'unit_cost' => 3, 'reason' => 'Weekly delivery',
        ]);
    }

    public function test_receive_stock_import_reports_unmatched_rows_without_dropping_the_whole_batch(): void
    {
        $tenantId = 'tenant-office-prod-receive-import-2';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $known = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Known Item', 'item_type' => 'product', 'price' => 1, 'sku' => 'KNOWN1', 'track_stock' => true]);

        $csv = "sku,barcode,name,qty,unit_cost\n"
            ."KNOWN1,,Known Item,6,2\n"
            ."GHOST1,,No Such Product,3,2\n";
        $file = UploadedFile::fake()->createWithContent('receive.csv', $csv);

        $response = $this->post('/office/products/receive-stock/import', [
            'file' => $file,
            'location_id' => $location->id,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('import_errors', fn (?array $errors) => $errors !== null && count($errors) === 1 && str_contains($errors[0], 'no product matched'));
        $this->assertSame('6.0000', $known->fresh()->stock_quantity);
    }

    public function test_receive_stock_import_rejects_a_location_from_another_tenant(): void
    {
        $otherTenantId = 'tenant-office-prod-receive-import-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignLocation = Location::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Their Warehouse']);

        $tenantId = 'tenant-office-prod-receive-import-3';
        $this->actingBackOfficeSession($tenantId);

        $csv = "sku,barcode,name,qty,unit_cost\nX,,X,1,1\n";
        $file = UploadedFile::fake()->createWithContent('receive.csv', $csv);

        $this->post('/office/products/receive-stock/import', [
            'file' => $file,
            'location_id' => $foreignLocation->id,
        ])->assertSessionHasErrors('location_id');
    }

    public function test_creating_a_product_with_a_location_breakdown_posts_one_movement_per_location(): void
    {
        $tenantId = 'tenant-office-prod-create-breakdown';
        $this->actingBackOfficeSession($tenantId);

        $warehouse1 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $warehouse2 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 2']);

        $response = $this->post('/office/products', [
            'name' => 'Multi-Location Item',
            'item_type' => 'product',
            'price' => 3,
            'track_stock' => true,
            'location_stock' => [
                ['location_id' => $warehouse1->id, 'quantity' => 20],
                ['location_id' => $warehouse2->id, 'quantity' => 15],
            ],
        ]);
        $response->assertRedirect();

        $product = Product::where('name', 'Multi-Location Item')->firstOrFail();

        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'location_id' => $warehouse1->id, 'quantity_change' => 20,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id, 'location_id' => $warehouse2->id, 'quantity_change' => 15,
        ]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse1->id, 'quantity' => 20]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse2->id, 'quantity' => 15]);
        $this->assertSame('35.0000', $product->fresh()->stock_quantity);
    }

    public function test_editing_a_product_with_a_location_breakdown_reconciles_each_location_independently(): void
    {
        $tenantId = 'tenant-office-prod-edit-breakdown';
        $this->actingBackOfficeSession($tenantId);

        $warehouse1 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $warehouse2 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 2']);

        $this->post('/office/products', [
            'name' => 'Edited Multi-Location Item',
            'item_type' => 'product',
            'price' => 3,
            'track_stock' => true,
            'location_stock' => [
                ['location_id' => $warehouse1->id, 'quantity' => 10],
                ['location_id' => $warehouse2->id, 'quantity' => 10],
            ],
        ])->assertRedirect();
        $product = Product::where('name', 'Edited Multi-Location Item')->firstOrFail();

        // Correct Warehouse 1 upward and leave Warehouse 2 untouched by
        // resubmitting its current figure — must not double it up.
        $this->put("/office/products/{$product->id}", [
            'name' => 'Edited Multi-Location Item',
            'item_type' => 'product',
            'price' => 3,
            'location_stock' => [
                ['location_id' => $warehouse1->id, 'quantity' => 25],
                ['location_id' => $warehouse2->id, 'quantity' => 10],
            ],
        ])->assertRedirect();

        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse1->id, 'quantity' => 25]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse2->id, 'quantity' => 10]);
        $this->assertSame('35.0000', $product->fresh()->stock_quantity);
    }

    public function test_template_download_has_the_expected_headers(): void
    {
        $this->actingBackOfficeSession('tenant-office-prod-10');

        $response = $this->get('/office/products/import/template');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith(
            'name,item_type,price,cost_price,sku,barcode,category,unit,track_stock,stock_quantity,low_stock_threshold',
            $response->streamedContent()
        );
    }

    public function test_template_download_has_one_stock_column_per_location_for_multi_location_businesses(): void
    {
        $tenantId = 'tenant-office-prod-template-multi';
        $this->actingBackOfficeSession($tenantId);

        Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 2']);

        $response = $this->get('/office/products/import/template');

        $response->assertOk();
        $this->assertStringStartsWith(
            'name,item_type,price,cost_price,sku,barcode,category,unit,track_stock,"stock: Warehouse 1","stock: Warehouse 2",low_stock_threshold',
            $response->streamedContent()
        );
    }

    public function test_import_populates_per_location_balances_from_stock_columns(): void
    {
        $tenantId = 'tenant-office-prod-import-multi';
        $this->actingBackOfficeSession($tenantId);

        $warehouse1 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        $warehouse2 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 2']);

        $csv = 'name,item_type,price,sku,"stock: Warehouse 1","stock: Warehouse 2"'."\n"
            .'Split Stock Soda,product,2.00,SPLIT1,30,20'."\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/office/products/import', ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionMissing('import_errors');

        $product = Product::where('sku', 'SPLIT1')->firstOrFail();
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse1->id, 'quantity' => 30]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse2->id, 'quantity' => 20]);
        $this->assertSame('50.0000', $product->fresh()->stock_quantity);
    }

    public function test_reimporting_with_stock_columns_reconciles_without_double_counting(): void
    {
        $tenantId = 'tenant-office-prod-import-multi-reimport';
        $this->actingBackOfficeSession($tenantId);

        $warehouse1 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);

        $csvHeader = 'name,item_type,price,sku,"stock: Warehouse 1"';
        $this->post('/office/products/import', [
            'file' => UploadedFile::fake()->createWithContent('import.csv', $csvHeader."\n".'Reimport Item,product,2.00,REIMP1,40'."\n"),
        ])->assertRedirect();

        $product = Product::where('sku', 'REIMP1')->firstOrFail();
        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse1->id, 'quantity' => 40]);

        // Re-importing the same file (matched by SKU this time) with a
        // corrected figure must reconcile to the new number, not add to it.
        $this->post('/office/products/import', [
            'file' => UploadedFile::fake()->createWithContent('import.csv', $csvHeader."\n".'Reimport Item,product,2.00,REIMP1,65'."\n"),
        ])->assertRedirect();

        $this->assertDatabaseHas('product_stock', ['product_id' => $product->id, 'location_id' => $warehouse1->id, 'quantity' => 65]);
        $this->assertSame('65.0000', $product->fresh()->stock_quantity);
    }

    public function test_export_lists_active_products_with_live_prices_and_balances(): void
    {
        $tenantId = 'tenant-office-prod-export';
        $this->actingBackOfficeSession($tenantId);

        Category::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Beverages']);

        $active = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Export Me',
            'item_type' => 'product',
            'price' => 2.5,
            'cost_price' => 1,
            'sku' => 'EXP1',
            'stock_quantity' => 17,
        ]);
        Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Archived Item',
            'item_type' => 'product',
            'price' => 1,
            'sku' => 'ARC1',
            'is_active' => false,
        ]);
        Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'A Container',
            'item_type' => 'container',
            'price' => 0,
            'deposit_amount' => 0.5,
            'sku' => 'CONT1',
        ]);

        $response = $this->get('/office/products/export');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringStartsWith(
            'name,item_type,price,cost_price,sku,barcode,category,unit,track_stock,stock_quantity,low_stock_threshold',
            $content
        );
        $this->assertStringContainsString('"Export Me",product,2.5000,1.0000,EXP1,,,piece,yes,17.0000,5.0000', $content);
        // Archived items and containers aren't part of the live catalogue this workflow reconciles.
        $this->assertStringNotContainsString('Archived Item', $content);
        $this->assertStringNotContainsString('A Container', $content);

        unset($active);
    }

    public function test_export_has_one_stock_column_per_location_for_multi_location_businesses(): void
    {
        $tenantId = 'tenant-office-prod-export-multi';
        $this->actingBackOfficeSession($tenantId);

        $warehouse1 = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 1']);
        Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Warehouse 2']);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Split Stock Item',
            'item_type' => 'product',
            'price' => 3,
            'sku' => 'SPLITEXP',
        ]);
        ProductStock::create([
            'id' => (string) Str::uuid(),
            'product_id' => $product->id,
            'location_id' => $warehouse1->id,
            'quantity' => 12,
            'reserved_quantity' => 0,
        ]);

        $response = $this->get('/office/products/export');

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringStartsWith(
            'name,item_type,price,cost_price,sku,barcode,category,unit,track_stock,"stock: Warehouse 1","stock: Warehouse 2",low_stock_threshold',
            $content
        );
        $this->assertStringContainsString('"Split Stock Item",product,3.0000,0.0000,SPLITEXP,,,piece,yes,12,0,5.0000', $content);
    }

    public function test_full_catalogue_import_reports_active_products_missing_from_the_file_without_changing_them(): void
    {
        $tenantId = 'tenant-office-prod-full-catalogue';
        $this->actingBackOfficeSession($tenantId);

        $inFile = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Still Here',
            'item_type' => 'product',
            'price' => 1,
            'sku' => 'STAY1',
            'stock_quantity' => 5,
        ]);
        $missing = Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Dropped From Sheet',
            'item_type' => 'product',
            'price' => 1,
            'sku' => 'GONE1',
            'stock_quantity' => 9,
            'is_active' => true,
        ]);

        $csv = "name,item_type,price,cost_price,sku,barcode,category,unit,track_stock,stock_quantity,low_stock_threshold\n"
            ."Still Here,product,1.00,0.00,STAY1,,,piece,yes,5,5\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/office/products/import', ['file' => $file, 'full_catalogue' => true]);

        $response->assertRedirect();
        $response->assertSessionHas('import_missing', function (?array $missingList) {
            return $missingList !== null && str_contains($missingList[0], 'Dropped From Sheet') && str_contains($missingList[0], 'GONE1');
        });

        // Untouched: still active, balance unchanged, no archive/deactivation happened.
        $this->assertTrue($missing->fresh()->is_active);
        $this->assertSame('9.0000', $missing->fresh()->stock_quantity);
        $this->assertTrue($inFile->fresh()->is_active);
    }

    public function test_import_without_full_catalogue_flag_does_not_report_missing_products(): void
    {
        $tenantId = 'tenant-office-prod-partial-import';
        $this->actingBackOfficeSession($tenantId);

        Product::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Not In This Small File',
            'item_type' => 'product',
            'price' => 1,
            'sku' => 'PARTIAL1',
        ]);

        $csv = "name,item_type,price\nJust One New Item,product,4\n";
        $file = UploadedFile::fake()->createWithContent('import.csv', $csv);

        $response = $this->post('/office/products/import', ['file' => $file]);

        $response->assertRedirect();
        $response->assertSessionMissing('import_missing');
    }

    public function test_index_defaults_to_hiding_archived_items_and_the_archived_tab_shows_only_them(): void
    {
        $tenantId = 'tenant-office-prod-status-filter';
        $this->actingBackOfficeSession($tenantId);

        $active = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Active Item', 'item_type' => 'product', 'price' => 1, 'is_active' => true]);
        $archived = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Archived Item', 'item_type' => 'product', 'price' => 1, 'is_active' => false]);

        $default = $this->get('/office/products');
        $defaultNames = collect($default->viewData('page')['props']['products']['data'])->pluck('name');
        $this->assertContains('Active Item', $defaultNames);
        $this->assertNotContains('Archived Item', $defaultNames);

        $archivedTab = $this->get('/office/products?status=archived');
        $archivedNames = collect($archivedTab->viewData('page')['props']['products']['data'])->pluck('name');
        $this->assertContains('Archived Item', $archivedNames);
        $this->assertNotContains('Active Item', $archivedNames);

        $all = $this->get('/office/products?status=all');
        $allNames = collect($all->viewData('page')['props']['products']['data'])->pluck('name');
        $this->assertContains('Active Item', $allNames);
        $this->assertContains('Archived Item', $allNames);

        $this->assertNotNull($active);
        $this->assertNotNull($archived);
    }

    public function test_merging_a_product_moves_stock_and_archives_the_duplicate(): void
    {
        $tenantId = 'tenant-office-prod-merge-1';
        $this->actingBackOfficeSession($tenantId);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Main']);
        $processor = app(SyncProcessor::class);

        $survivor = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Coca Cola 500ml', 'item_type' => 'product', 'price' => 1.5, 'track_stock' => true]);
        $processor->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId, 'location_id' => $location->id, 'product_id' => $survivor->id,
            'type' => 'opening_stock', 'quantity_change' => 10,
        ]);

        $duplicate = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Coke 500ml (dup)', 'item_type' => 'product', 'price' => 1.5, 'track_stock' => true]);
        $processor->process('stock_movements', (string) Str::uuid(), 'upsert', [
            'business_id' => $tenantId, 'location_id' => $location->id, 'product_id' => $duplicate->id,
            'type' => 'opening_stock', 'quantity_change' => 8,
        ]);

        $response = $this->post("/office/products/{$duplicate->id}/merge", ['into' => $survivor->id]);
        $response->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $duplicate->id, 'is_active' => false, 'merged_into_product_id' => $survivor->id]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $duplicate->id, 'location_id' => $location->id, 'type' => 'merge_out', 'quantity_change' => -8]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $survivor->id, 'location_id' => $location->id, 'type' => 'merge_in', 'quantity_change' => 8]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $survivor->id, 'location_id' => $location->id, 'quantity' => 18]);
        $this->assertDatabaseHas('product_stock', ['product_id' => $duplicate->id, 'location_id' => $location->id, 'quantity' => 0]);
    }

    public function test_cannot_merge_a_product_into_a_different_item_type(): void
    {
        $tenantId = 'tenant-office-prod-merge-2';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'A Product', 'item_type' => 'product', 'price' => 1]);
        $service = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'A Service', 'item_type' => 'service', 'price' => 5]);

        $this->post("/office/products/{$product->id}/merge", ['into' => $service->id])
            ->assertSessionHasErrors('into');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => true]);
    }

    public function test_cannot_merge_into_an_archived_item_or_into_itself(): void
    {
        $tenantId = 'tenant-office-prod-merge-3';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Solo Item', 'item_type' => 'product', 'price' => 1]);
        $archivedTarget = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Archived Target', 'item_type' => 'product', 'price' => 1, 'is_active' => false]);

        $this->post("/office/products/{$product->id}/merge", ['into' => $archivedTarget->id])
            ->assertSessionHasErrors('into');

        $this->post("/office/products/{$product->id}/merge", ['into' => $product->id])
            ->assertSessionHasErrors('into');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => true]);
    }

    public function test_merge_rejects_a_target_from_another_tenant(): void
    {
        $otherTenantId = 'tenant-office-prod-merge-other';
        Tenant::firstOrCreate(['id' => $otherTenantId], ['business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com', 'pairing_code' => substr(md5($otherTenantId), 0, 6)]);
        $foreignProduct = Product::create(['id' => (string) Str::uuid(), 'business_id' => $otherTenantId, 'name' => 'Not Yours', 'item_type' => 'product', 'price' => 1]);

        $tenantId = 'tenant-office-prod-merge-4';
        $this->actingBackOfficeSession($tenantId);
        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Mine', 'item_type' => 'product', 'price' => 1]);

        $this->post("/office/products/{$product->id}/merge", ['into' => $foreignProduct->id])
            ->assertSessionHasErrors('into');
    }

    public function test_archive_all_deactivates_every_active_product_and_is_reversible_per_item(): void
    {
        $tenantId = 'tenant-office-prod-archive-all';
        $this->actingBackOfficeSession($tenantId);

        $a = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Item A', 'item_type' => 'product', 'price' => 1]);
        $b = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Item B', 'item_type' => 'product', 'price' => 1]);
        $alreadyArchived = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Already Archived', 'item_type' => 'product', 'price' => 1, 'is_active' => false]);

        $response = $this->post('/office/products/archive-all');
        $response->assertRedirect();

        $this->assertDatabaseHas('products', ['id' => $a->id, 'is_active' => false]);
        $this->assertDatabaseHas('products', ['id' => $b->id, 'is_active' => false]);
        $this->assertDatabaseHas('products', ['id' => $alreadyArchived->id, 'is_active' => false]);

        // Reversible per item, same as a normal archive.
        $this->patch("/office/products/{$a->id}/toggle-active")->assertRedirect();
        $this->assertDatabaseHas('products', ['id' => $a->id, 'is_active' => true]);
    }

    public function test_archive_all_is_owner_only(): void
    {
        $tenantId = 'tenant-office-prod-archive-all-role';
        $this->actingBackOfficeSession($tenantId, role: 'cashier');

        $product = Product::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Protected Item', 'item_type' => 'product', 'price' => 1]);

        $this->post('/office/products/archive-all')->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => true]);
    }
}
