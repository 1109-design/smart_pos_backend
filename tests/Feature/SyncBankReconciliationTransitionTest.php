<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Two-till concurrency audit follow-up: bank_reconciliations is bidirectional
 * (started/completed/cancelled from any device or BackOffice — see
 * sync_service.dart's doc comment) but had no domain-aware transition guard,
 * so a device that started a session and stayed offline could resync its
 * own stale 'in_progress' snapshot after another device already completed
 * or cancelled it, silently regressing status and wiping
 * completed_by_user_id/completed_at. Mirrors SyncStockTransferTransitionTest.
 */
class SyncBankReconciliationTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '88888888-8888-4888-8888-888888888888',
            'email' => 'sync-reconciliation-owner@example.com',
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

    private function pushReconciliation(string $token, string $tenantId, string $reconciliationId, string $status): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'bank_reconciliations',
                    'uuid' => $reconciliationId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'bank_account_id' => '33333333-3333-4333-8333-333333333333',
                        'statement_date' => now()->toDateString(),
                        'statement_balance' => 1000,
                        'status' => $status,
                        'started_by_user_id' => '88888888-8888-4888-8888-888888888888',
                        'started_at' => now()->toIso8601String(),
                        'completed_by_user_id' => $status === 'completed' ? '88888888-8888-4888-8888-888888888888' : null,
                        'completed_at' => $status === 'completed' ? now()->toIso8601String() : null,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_completing_directly_from_in_progress_is_allowed(): void
    {
        $tenantId = 'tenant-reconciliation-1';
        $token = $this->actingDeviceToken($tenantId);
        $reconciliationId = (string) Str::uuid();

        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'in_progress')->assertOk();
        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'completed')->assertOk();

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'completed',
        ]);
    }

    public function test_a_completed_reconciliation_cannot_be_reopened(): void
    {
        $tenantId = 'tenant-reconciliation-2';
        $token = $this->actingDeviceToken($tenantId);
        $reconciliationId = (string) Str::uuid();

        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'in_progress')->assertOk();
        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'completed')->assertOk();

        // Simulates the exact race the audit flagged: a device that started
        // the session offline, then replays its own stale 'in_progress'
        // push after another device (or BackOffice) already completed it.
        $response = $this->pushReconciliation($token, $tenantId, $reconciliationId, 'in_progress');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'completed',
        ]);
        $this->assertDatabaseMissing('bank_reconciliations', [
            'id' => $reconciliationId,
            'completed_at' => null,
        ]);
    }

    public function test_a_cancelled_reconciliation_cannot_be_completed(): void
    {
        $tenantId = 'tenant-reconciliation-3';
        $token = $this->actingDeviceToken($tenantId);
        $reconciliationId = (string) Str::uuid();

        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'in_progress')->assertOk();
        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'cancelled')->assertOk();

        $response = $this->pushReconciliation($token, $tenantId, $reconciliationId, 'completed');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'cancelled',
        ]);
    }

    public function test_resyncing_the_same_status_is_a_safe_no_op(): void
    {
        $tenantId = 'tenant-reconciliation-4';
        $token = $this->actingDeviceToken($tenantId);
        $reconciliationId = (string) Str::uuid();

        $this->pushReconciliation($token, $tenantId, $reconciliationId, 'in_progress')->assertOk();
        $response = $this->pushReconciliation($token, $tenantId, $reconciliationId, 'in_progress');

        $response->assertOk();
        $response->assertJsonCount(0, 'errors');

        $this->assertDatabaseHas('bank_reconciliations', [
            'id' => $reconciliationId,
            'status' => 'in_progress',
        ]);
    }
}
