<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Category;
use App\Models\Device;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductContainerLink;
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

    private function actingBackOfficeSession(string $tenantId): User
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
                'role' => 'business_owner',
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
        // Stock is ledger-owned after creation — the CSV's 999 must be ignored for an existing item.
        $this->assertDatabaseHas('products', ['id' => $existingId, 'stock_quantity' => 42]);

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
}
