<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * 2026-10-10: approved goods receipts were posted once per till that
 * pulled the approval. The cleanup offsets each duplicate's stock and
 * cancels its PO — 'received' is otherwise terminal, so a cancel is only
 * accepted once every receipt on the PO has been fully reversed. Its push
 * also carries a product_stock snapshot taken before the offsetting
 * movement, which must not overwrite the ledger.
 */
class SyncDuplicateReceiptCleanupTest extends TestCase
{
    use RefreshDatabase;

    private const USER_ID = '77777777-7777-4777-8777-777777777777';

    private string $tenantId = 'tenant-dup-receipt';

    private string $token;

    private string $productId;

    private string $locationId;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => 'dup@example.com']);
        $user = User::factory()->create(['id' => self::USER_ID, 'email' => 'dup-receipt-owner@example.com']);
        $this->token = $user->createToken('sync-test')->plainTextToken;
        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'token_id' => (int) explode('|', $this->token)[0],
            'is_revoked' => false,
        ]);

        $this->productId = (string) Str::uuid();
        $this->locationId = (string) Str::uuid();
        Product::create([
            'id' => $this->productId,
            'business_id' => $this->tenantId,
            'name' => 'Work Suit',
            'price' => 30,
            'stock_quantity' => 0,
        ]);
    }

    private function push(array $records): TestResponse
    {
        $now = now()->toIso8601String();

        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/sync/push', [
                'records' => array_map(fn (array $r) => [
                    'table' => $r[0],
                    'uuid' => $r[1],
                    'operation' => 'upsert',
                    'payload' => $r[2] + ['updated_at' => $now],
                    'updated_at' => $now,
                ], $records),
            ]);
    }

    private function poPayload(string $status): array
    {
        return [
            'business_id' => $this->tenantId,
            'receiving_location_id' => $this->locationId,
            'po_number' => 'PO-20261010-248A-001',
            'status' => $status,
            'created_by_user_id' => self::USER_ID,
        ];
    }

    private function movement(string $type, float $qty, string $referenceId): array
    {
        return [
            'business_id' => $this->tenantId,
            'location_id' => $this->locationId,
            'product_id' => $this->productId,
            'type' => $type,
            'quantity_change' => $qty,
            'reference_id' => $referenceId,
            'user_id' => self::USER_ID,
        ];
    }

    /** A PO received with one 10-unit receipt; returns [poId, receiveId]. */
    private function receivedPo(): array
    {
        $poId = (string) Str::uuid();
        $receiveId = (string) Str::uuid();
        $this->push([
            ['purchase_orders', $poId, $this->poPayload('received')],
            ['stock_movements', $receiveId, $this->movement('receive', 10, $poId)],
        ])->assertOk();

        return [$poId, $receiveId];
    }

    private function poStatus(string $poId): string
    {
        return PurchaseOrder::findOrFail($poId)->status;
    }

    public function test_a_fully_reversed_receipt_lets_the_po_be_cancelled(): void
    {
        [$poId, $receiveId] = $this->receivedPo();

        $this->push([
            ['stock_movements', (string) Str::uuid(), $this->movement('adjustment', -10, $receiveId)],
            ['purchase_orders', $poId, $this->poPayload('cancelled')],
        ])->assertOk();

        $this->assertSame('cancelled', $this->poStatus($poId));
        $this->assertDatabaseHas('product_stock', [
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'quantity' => 0,
        ]);
    }

    public function test_a_received_po_with_stock_still_on_hand_cannot_be_cancelled(): void
    {
        [$poId, $receiveId] = $this->receivedPo();

        // Not offset at all.
        $this->push([['purchase_orders', $poId, $this->poPayload('cancelled')]]);
        $this->assertSame('received', $this->poStatus($poId));

        // Only partly offset.
        $this->push([
            ['stock_movements', (string) Str::uuid(), $this->movement('adjustment', -4, $receiveId)],
            ['purchase_orders', $poId, $this->poPayload('cancelled')],
        ]);
        $this->assertSame('received', $this->poStatus($poId));
    }

    public function test_a_stale_product_stock_snapshot_cannot_overwrite_the_ledger(): void
    {
        [, $receiveId] = $this->receivedPo();
        $this->push([
            ['stock_movements', (string) Str::uuid(), $this->movement('receive', 10, (string) Str::uuid())],
        ])->assertOk();

        // Snapshot taken before the offset (20), then the offset, then the
        // snapshot again after it — the ledger (10) wins either way.
        $snapshot = [
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'quantity' => 20,
            'reserved_quantity' => 0,
        ];
        $rowId = $this->productId.'|'.$this->locationId;
        $this->push([
            ['product_stock', $rowId, $snapshot],
            ['stock_movements', (string) Str::uuid(), $this->movement('adjustment', -10, $receiveId)],
        ])->assertOk();
        $this->push([['product_stock', $rowId, $snapshot]])->assertOk();

        $this->assertDatabaseHas('product_stock', [
            'product_id' => $this->productId,
            'location_id' => $this->locationId,
            'quantity' => 10,
        ]);
        $this->assertSame(10.0, (float) StockMovement::where('product_id', $this->productId)->sum('quantity_change'));
    }
}
