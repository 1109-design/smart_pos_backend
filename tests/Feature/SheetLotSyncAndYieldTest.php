<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Device;
use App\Models\Product;
use App\Models\SheetLot;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * GLS·01 — sheet_lots/sheet_cuts are till-authored (unlike Requisitions/
 * Projects), so this covers the real device sync push path, plus the
 * BackOffice yield report reading the result back.
 */
class SheetLotSyncAndYieldTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'email' => $tenantId.'-user@example.com', 'is_active' => true]);
        session(['backoffice' => [
            'tenant_id' => $tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => 'business_owner', 'business_name' => $tenantId, 'currency_code' => 'USD',
        ]]);

        return $user;
    }

    public function test_a_received_sheet_lot_pushes_and_pulls_correctly(): void
    {
        $tenantId = 'tenant-sheet-1';
        $token = $this->actingDeviceToken($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Glass 4mm',
            'item_type' => 'sheet', 'price' => 25, 'sheet_width' => 2.44, 'sheet_height' => 1.22,
            'track_stock' => true, 'stock_quantity' => 0,
        ]);
        $lotId = (string) Str::uuid();

        $push = $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'sheet_lots', 'uuid' => $lotId, 'operation' => 'upsert',
                'payload' => [
                    'business_id' => $tenantId, 'product_id' => $product->id,
                    'original_width' => 2.44, 'original_height' => 1.22, 'area' => 2.9768, 'status' => 'available',
                ],
                'updated_at' => now()->toIso8601String(),
            ]],
        ]);
        $push->assertOk();

        $lot = SheetLot::findOrFail($lotId);
        $this->assertSame('2.9768', $lot->area);
        $this->assertSame('available', $lot->status);

        $otherToken = $this->actingDeviceToken($tenantId.'-other');
        Device::where('tenant_id', $tenantId.'-other')->update(['tenant_id' => $tenantId]);
        $pull = $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson('/api/v1/sync/pull?'.http_build_query(['tables' => ['sheet_lots']]));
        $pull->assertOk();
        $this->assertContains($lotId, collect($pull->json('records'))->pluck('record_uuid')->all());
    }

    public function test_a_cut_against_a_lot_decrements_its_area_via_a_second_push(): void
    {
        $tenantId = 'tenant-sheet-2';
        $token = $this->actingDeviceToken($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Glass 4mm',
            'item_type' => 'sheet', 'price' => 25, 'sheet_width' => 2.44, 'sheet_height' => 1.22,
            'track_stock' => true, 'stock_quantity' => 0,
        ]);
        $lotId = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'sheet_lots', 'uuid' => $lotId, 'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'product_id' => $product->id, 'original_width' => 2.44, 'original_height' => 1.22, 'area' => 2.9768, 'status' => 'available'],
                'updated_at' => now()->toIso8601String(),
            ]],
        ]);

        $cutId = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [
                [
                    'table' => 'sheet_cuts', 'uuid' => $cutId, 'operation' => 'upsert',
                    'payload' => ['sheet_lot_id' => $lotId, 'width' => 1, 'height' => 0.5, 'area' => 0.5, 'cut_at' => now()->toIso8601String()],
                    'updated_at' => now()->toIso8601String(),
                ],
                [
                    // The till also pushes the lot's own updated remaining area.
                    'table' => 'sheet_lots', 'uuid' => $lotId, 'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'product_id' => $product->id, 'original_width' => 2.44, 'original_height' => 1.22, 'area' => 2.4768, 'status' => 'available'],
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('sheet_cuts', ['id' => $cutId, 'sheet_lot_id' => $lotId, 'area' => 0.5]);
        $this->assertSame('2.4768', SheetLot::findOrFail($lotId)->area);
    }

    public function test_sheet_cuts_cannot_be_deleted_being_an_immutable_ledger(): void
    {
        $tenantId = 'tenant-sheet-3';
        $token = $this->actingDeviceToken($tenantId);
        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Glass 4mm',
            'item_type' => 'sheet', 'price' => 25, 'sheet_width' => 1, 'sheet_height' => 1,
            'track_stock' => true, 'stock_quantity' => 0,
        ]);
        $lot = SheetLot::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'product_id' => $product->id,
            'original_width' => 1, 'original_height' => 1, 'area' => 1, 'status' => 'available',
        ]);
        $cutId = (string) Str::uuid();
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'sheet_cuts', 'uuid' => $cutId, 'operation' => 'upsert',
                'payload' => ['sheet_lot_id' => $lot->id, 'width' => 1, 'height' => 1, 'area' => 1, 'cut_at' => now()->toIso8601String()],
                'updated_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();
        $this->assertDatabaseHas('sheet_cuts', ['id' => $cutId]);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', [
            'records' => [[
                'table' => 'sheet_cuts', 'uuid' => $cutId, 'operation' => 'delete',
                'updated_at' => now()->toIso8601String(),
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('sheet_cuts', ['id' => $cutId]);
    }

    public function test_product_form_accepts_item_type_sheet_with_dimensions(): void
    {
        $tenantId = 'tenant-sheet-4';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/products', [
            'name' => 'Glass 6mm',
            'item_type' => 'sheet',
            'price' => 30,
            'sheet_width' => 2.44,
            'sheet_height' => 1.83,
        ])->assertRedirect();

        $product = Product::where('business_id', $tenantId)->where('name', 'Glass 6mm')->first();
        $this->assertNotNull($product);
        $this->assertSame('sheet', $product->item_type);
        $this->assertSame('2.4400', $product->sheet_width);
        $this->assertSame('1.8300', $product->sheet_height);
        // Stock is entirely lot-driven — never set at creation time.
        $this->assertSame('0.0000', $product->stock_quantity);
    }

    public function test_product_form_requires_dimensions_for_sheet_type(): void
    {
        $tenantId = 'tenant-sheet-5';
        $this->actingBackOfficeSession($tenantId);

        $this->post('/office/products', [
            'name' => 'Glass 6mm',
            'item_type' => 'sheet',
            'price' => 30,
        ])->assertSessionHasErrors(['sheet_width', 'sheet_height']);
    }

    public function test_yield_report_shows_purchased_cut_and_remaining_area(): void
    {
        $tenantId = 'tenant-sheet-6';
        $this->actingBackOfficeSession($tenantId);

        $product = Product::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Glass 4mm',
            'item_type' => 'sheet', 'price' => 25, 'sheet_width' => 2.44, 'sheet_height' => 1.22,
            'track_stock' => true, 'stock_quantity' => 0,
        ]);
        $lot = SheetLot::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'product_id' => $product->id,
            'original_width' => 2.44, 'original_height' => 1.22, 'area' => 2.4768, 'status' => 'available',
        ]);
        $lot->cuts()->create(['id' => (string) Str::uuid(), 'width' => 1, 'height' => 0.5, 'area' => 0.5, 'cut_at' => now()]);

        $response = $this->get('/office/reports/sheet-yield');

        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/SheetYield')
            ->where('lots.0.original_area', 2.9768)
            ->where('lots.0.cut_area', 0.5)
            ->where('lots.0.remaining_area', 2.4768)
            ->where('lots.0.cut_count', 1)
        );
    }
}
