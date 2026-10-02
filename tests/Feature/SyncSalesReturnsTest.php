<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\SalesReturn;
use App\Models\SalesReturnItem;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer returns pushed from a till — the return record, its items, and
 * the owner-only return period.
 */
class SyncSalesReturnsTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $tenantId, string $role): string
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        if (! Tenant::find($tenantId)) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
            Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        }
        $user = User::factory()->create(['business_id' => $tenantId, 'email' => "{$tenantId}-{$role}@example.com"]);
        $user->assignRole($role);

        $plain = $user->createToken('sync-test')->plainTextToken;
        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function push(string $token, array $records)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => array_map(fn ($r) => $r + [
                'operation' => 'upsert',
                'updated_at' => now()->toIso8601String(),
            ], $records)]);
    }

    public function test_a_return_and_its_items_are_stored(): void
    {
        $tenantId = 'tenant-returns-1';
        $token = $this->tokenFor($tenantId, 'business_owner');
        $returnId = (string) Str::uuid();
        $itemId = (string) Str::uuid();

        $response = $this->push($token, [
            [
                'table' => 'sales_returns',
                'uuid' => $returnId,
                'payload' => [
                    'business_id' => $tenantId,
                    'return_number' => 'RET-20260929-A1B2-001',
                    'original_transaction_id' => (string) Str::uuid(),
                    'return_transaction_id' => (string) Str::uuid(),
                    'exchange_transaction_id' => (string) Str::uuid(),
                    'outcome' => 'exchange',
                    'returned_value' => 10,
                    'new_items_value' => 15,
                    'net_amount' => 5,
                    'settlement_method' => 'Cash',
                    'reason' => 'Wrong size',
                    'requested_by_user_id' => (string) Str::uuid(),
                ],
            ],
            [
                'table' => 'sales_return_items',
                'uuid' => $itemId,
                'payload' => [
                    'sales_return_id' => $returnId,
                    'original_transaction_item_id' => (string) Str::uuid(),
                    'product_id' => (string) Str::uuid(),
                    'product_name' => 'Widget F',
                    'quantity' => 1,
                    'unit_value' => 10,
                    'line_value' => 10,
                    'condition' => 'damaged',
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertSame('exchange', SalesReturn::findOrFail($returnId)->outcome);
        $this->assertSame('damaged', SalesReturnItem::findOrFail($itemId)->condition);
    }

    public function test_a_manager_cannot_change_the_return_period(): void
    {
        $tenantId = 'tenant-returns-2';
        $token = $this->tokenFor($tenantId, 'manager');

        $this->push($token, [[
            'table' => 'sales_return_settings',
            'uuid' => $tenantId,
            'payload' => ['business_id' => $tenantId, 'return_window_days' => 7],
        ]])->assertJsonCount(1, 'errors');

        $this->assertNull(DB::table('sales_return_settings')->where('business_id', $tenantId)->first());
    }

    public function test_the_owner_can_change_the_return_period(): void
    {
        $tenantId = 'tenant-returns-3';
        $token = $this->tokenFor($tenantId, 'business_owner');

        $this->push($token, [[
            'table' => 'sales_return_settings',
            'uuid' => $tenantId,
            'payload' => ['business_id' => $tenantId, 'return_window_days' => 7],
        ]])->assertJsonCount(1, 'accepted');

        $this->assertSame(7, (int) DB::table('sales_return_settings')->where('business_id', $tenantId)->value('return_window_days'));
    }
}
