<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrder;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SyncProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-28 sync audit finding C2: SyncProcessor::handleDelete() never
 * received $trusted/$actingUser, unlike handleUpsert() — so any
 * authenticated device (including a plain cashier till, or a scripted
 * replay against the raw API) could hard-delete a transaction, purchase
 * order, customer, invoice, credit note, or salary payment with zero
 * authorization and no soft-delete recovery path. Fixed by threading
 * $trusted into handleDelete() and refusing an untrusted hard delete for
 * every table with no confirmed legitimate client-originated delete flow
 * (SyncProcessor::UNTRUSTED_HARD_DELETE_ALLOWED).
 */
class SyncDeleteAuthorizationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com', 'business_id' => $tenantId]);

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

    private function pushDelete(string $token, string $table, string $uuid)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => $table,
                    'uuid' => $uuid,
                    'operation' => 'delete',
                    'payload' => [],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_untrusted_push_cannot_hard_delete_a_transaction(): void
    {
        $tenantId = 'tenant-del-guard-tx';
        $token = $this->actingDeviceToken($tenantId);
        $user = User::where('business_id', $tenantId)->first();
        $txId = (string) Str::uuid();
        Transaction::create([
            'id' => $txId, 'business_id' => $tenantId, 'user_id' => $user->id,
            'subtotal' => 10, 'total' => 10, 'tax_total' => 0, 'base_currency' => 'USD', 'status' => 'completed',
        ]);

        $response = $this->pushDelete($token, 'transactions', $txId);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('transactions', ['id' => $txId]);
    }

    public function test_untrusted_push_cannot_hard_delete_a_purchase_order(): void
    {
        $tenantId = 'tenant-del-guard-po';
        $token = $this->actingDeviceToken($tenantId);
        $user = User::where('business_id', $tenantId)->first();
        $poId = (string) Str::uuid();
        PurchaseOrder::create([
            'id' => $poId, 'business_id' => $tenantId, 'po_number' => 'PO-1',
            'created_by_user_id' => $user->id,
        ]);

        $response = $this->pushDelete($token, 'purchase_orders', $poId);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('purchase_orders', ['id' => $poId]);
    }

    public function test_untrusted_push_cannot_hard_delete_a_customer(): void
    {
        $tenantId = 'tenant-del-guard-cust';
        $token = $this->actingDeviceToken($tenantId);
        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Victim Customer']);

        $response = $this->pushDelete($token, 'customers', $customerId);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('customers', ['id' => $customerId]);
    }

    /**
     * The block-list must not break a delete flow the app genuinely relies
     * on today (product_form_screen.dart removes a selling-unit row via a
     * real sync delete) — an untrusted push targeting a non-blocked table
     * still succeeds.
     */
    public function test_untrusted_push_can_still_delete_a_non_blocked_table(): void
    {
        $tenantId = 'tenant-del-guard-allowlist';
        $token = $this->actingDeviceToken($tenantId);
        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Widget', 'item_type' => 'product', 'price' => 10]);
        $unitId = (string) Str::uuid();
        ProductUnit::create(['id' => $unitId, 'product_id' => $productId, 'unit_name' => 'box', 'conversion_factor' => 12]);

        $response = $this->pushDelete($token, 'product_units', $unitId);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $response->assertJsonCount(0, 'errors');
        $this->assertDatabaseMissing('product_units', ['id' => $unitId]);
    }

    /**
     * A trusted (server-authored) delete of a sensitive table — the shape
     * BackOffice/artisan commands use — must keep working; the gate only
     * fires for untrusted (device-push) deletes.
     */
    public function test_trusted_delete_of_sensitive_table_still_works(): void
    {
        $tenantId = 'tenant-del-guard-trusted';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $user = User::factory()->create(['business_id' => $tenantId, 'email' => 'trusted-owner@example.com']);
        $poId = (string) Str::uuid();
        PurchaseOrder::create([
            'id' => $poId, 'business_id' => $tenantId, 'po_number' => 'PO-2',
            'created_by_user_id' => $user->id,
        ]);

        app(SyncProcessor::class)->process('purchase_orders', $poId, 'delete', ['business_id' => $tenantId]);

        $this->assertDatabaseMissing('purchase_orders', ['id' => $poId]);
    }
}
