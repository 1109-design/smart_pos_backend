<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncShiftTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-shift-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    /**
     * Regression test: Shift::$fillable was missing location_id, so every
     * shift synced from a till landed with location_id = null even though
     * the device sent it and SyncProcessor forwarded it — see
     * app/Models/Shift.php.
     */
    public function test_shift_pushed_from_a_device_keeps_its_location_id(): void
    {
        $tenantId = 'tenant-sync-shift-1';
        $token = $this->actingDeviceToken($tenantId);

        $shiftId = (string) Str::uuid();
        $locationId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'shifts',
                    'uuid' => $shiftId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $locationId,
                        'cashier_id' => '99999999-9999-4999-9999-999999999999',
                        'opened_at' => now()->toIso8601String(),
                        'status' => 'open',
                        'opening_float' => 50,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('shifts', [
            'id' => $shiftId,
            'business_id' => $tenantId,
            'location_id' => $locationId,
        ]);

        $this->assertSame($locationId, Shift::find($shiftId)->location_id);
    }

    private function push(string $token, string $table, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => $table,
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => $payload,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    /**
     * Live-verification finding: a cashier's shift-close push could claim
     * any total_sales/expected_cash it liked, with no independent
     * derivation from the shift's real transactions — classic till-skimming
     * (under-report takings, the shortfall never shows as a variance).
     * recomputeShiftFigures() now derives these from the real ledger
     * regardless of what the close payload claims.
     */
    public function test_a_shift_close_cannot_under_report_real_sales(): void
    {
        $tenantId = 'tenant-sync-shift-2';
        $token = $this->actingDeviceToken($tenantId);
        $cashierId = '99999999-9999-4999-9999-999999999999';
        $shiftId = (string) Str::uuid();

        $this->push($token, 'shifts', $shiftId, [
            'business_id' => $tenantId,
            'cashier_id' => $cashierId,
            'opened_at' => now()->subHour()->toIso8601String(),
            'status' => 'open',
            'opening_float' => 100,
        ])->assertOk();

        $transactionId = (string) Str::uuid();
        $this->push($token, 'transactions', $transactionId, [
            'business_id' => $tenantId,
            'user_id' => $cashierId,
            'subtotal' => 100,
            'total' => 100,
            'base_currency' => 'USD',
            'status' => 'completed',
        ])->assertOk();

        $paymentId = (string) Str::uuid();
        $this->push($token, 'payments', $paymentId, [
            'transaction_id' => $transactionId,
            'method' => 'cash',
            'amount' => 100,
            'currency_code' => 'USD',
            'base_equivalent' => 100,
        ])->assertOk();

        // Attempt to close the shift claiming almost nothing was sold and
        // only $10 was counted in the drawer.
        $this->push($token, 'shifts', $shiftId, [
            'status' => 'closed',
            'closed_at' => now()->toIso8601String(),
            'total_sales' => 1,
            'cash_sales' => 1,
            'expected_cash' => 1,
            'counted_cash' => 10,
            'variance' => 9,
        ])->assertOk();

        $shift = Shift::find($shiftId);
        // The real $100 cash sale, not the claimed $1.
        $this->assertEquals(100, (float) $shift->total_sales);
        $this->assertEquals(100, (float) $shift->cash_sales);
        // opening_float(100) + cashSales(100) = 200 expected in the drawer.
        $this->assertEquals(200, (float) $shift->expected_cash);
        // counted_cash is left as reported (a physical count).
        $this->assertEquals(10, (float) $shift->counted_cash);
        // The shortfall is now a real, visible variance, not hidden.
        $this->assertEquals(-190, (float) $shift->variance);
        // opening_float itself was preserved from the original open push.
        $this->assertEquals(100, (float) $shift->opening_float);
    }
}
