<?php

namespace Tests\Feature;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zimra\ZimraDevice;
use App\Models\Zimra\ZimraSale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncFiscalisationGateTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-fiscal-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function pushTransaction(string $token, string $tenantId, string $transactionId): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $transactionId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'user_id' => '99999999-9999-4999-9999-999999999999',
                        'subtotal' => 10,
                        'tax_total' => 1.5,
                        'total' => 10,
                        'base_currency' => 'USD',
                        'status' => 'completed',
                        'sale_number' => '202607-TEST-1',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_no_fiscalisation_when_business_switch_is_off(): void
    {
        Queue::fake();
        $tenantId = 'tenant-fiscal-1';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Switch Off Shop',
            'fiscalisation_enabled' => false,
        ]);

        $transactionId = (string) Str::uuid();
        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();

        $this->assertDatabaseCount('zimra_sales', 0);
        Queue::assertNotPushed(ProcessZimraFiscalisationJob::class);

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'fiscal_status' => null,
        ]);
    }

    public function test_fiscalisation_queued_when_switch_on_and_device_configured(): void
    {
        Queue::fake();
        $tenantId = 'tenant-fiscal-2';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Fiscal Shop',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        ZimraDevice::create([
            'business_id' => $tenantId,
            'tin' => '1234567890',
            'device_id' => '12345',
            'is_active' => true,
            'status' => 'active',
        ]);

        $transactionId = (string) Str::uuid();
        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();

        $this->assertDatabaseHas('zimra_sales', [
            'transaction_id' => $transactionId,
            'device_id' => '12345',
            'status' => 'pending',
        ]);
        Queue::assertPushed(ProcessZimraFiscalisationJob::class);

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'fiscal_status' => 'pending',
        ]);
    }

    public function test_switch_on_without_device_marks_not_configured(): void
    {
        Queue::fake();
        $tenantId = 'tenant-fiscal-3';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'No Device Shop',
            'fiscalisation_enabled' => true,
        ]);

        $transactionId = (string) Str::uuid();
        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();

        $this->assertDatabaseCount('zimra_sales', 0);
        Queue::assertNotPushed(ProcessZimraFiscalisationJob::class);

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'fiscal_status' => 'not_configured',
        ]);
    }

    public function test_a_layby_transitioning_to_completed_gets_fiscalised(): void
    {
        // Regression: the gate used to be "!txExists && status === completed".
        // A layby is created first with status 'layby', so by the time it's
        // paid off and flipped to 'completed' the transaction already exists
        // — that never queued fiscalisation, even though it's a genuine
        // completed sale at that point.
        Queue::fake();
        $tenantId = 'tenant-fiscal-5';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Layby Shop',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        ZimraDevice::create([
            'business_id' => $tenantId,
            'tin' => '1234567890',
            'device_id' => '12347',
            'is_active' => true,
            'status' => 'active',
        ]);

        $transactionId = (string) Str::uuid();

        // Created as a layby — must not fiscalise yet.
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $transactionId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'user_id' => '99999999-9999-4999-9999-999999999999',
                        'subtotal' => 10,
                        'tax_total' => 1.5,
                        'total' => 10,
                        'base_currency' => 'USD',
                        'status' => 'layby',
                        'sale_number' => '202607-TEST-LAYBY',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseCount('zimra_sales', 0);
        Queue::assertNotPushed(ProcessZimraFiscalisationJob::class);

        // Paid off in full — status flips to 'completed' on the same,
        // already-existing transaction row.
        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();

        $this->assertDatabaseHas('zimra_sales', [
            'transaction_id' => $transactionId,
            'device_id' => '12347',
            'status' => 'pending',
        ]);
        Queue::assertPushed(ProcessZimraFiscalisationJob::class);
    }

    public function test_updating_an_existing_transaction_does_not_requeue_fiscalisation(): void
    {
        Queue::fake();
        $tenantId = 'tenant-fiscal-4';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Fiscal Shop',
            'fiscalisation_enabled' => true,
        ]);

        ZimraDevice::create([
            'business_id' => $tenantId,
            'tin' => '1234567890',
            'device_id' => '12346',
            'is_active' => true,
            'status' => 'active',
        ]);

        $transactionId = (string) Str::uuid();
        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();
        $firstCount = ZimraSale::count();

        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();

        $this->assertSame($firstCount, ZimraSale::count());
    }
}
