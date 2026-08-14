<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncStockTakeTransitionTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'id' => '99999999-9999-4999-9999-999999999999',
            'email' => 'sync-stocktake-owner@example.com',
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

    private function pushStockTake(string $token, string $tenantId, string $stockTakeId, string $status, ?string $reviewComment = null): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'stock_takes',
                    'uuid' => $stockTakeId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'title' => 'Weekly Count',
                        'status' => $status,
                        'review_comment' => $reviewComment,
                        'created_by_user_id' => '99999999-9999-4999-9999-999999999999',
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_reverse_to_previous_stage_is_allowed_from_pending_approval(): void
    {
        $tenantId = 'tenant-stocktake-1';
        $token = $this->actingDeviceToken($tenantId);
        $stockTakeId = (string) Str::uuid();

        $this->pushStockTake($token, $tenantId, $stockTakeId, 'draft')->assertOk();
        $this->pushStockTake($token, $tenantId, $stockTakeId, 'in_progress')->assertOk();
        $this->pushStockTake($token, $tenantId, $stockTakeId, 'pending_approval')->assertOk();

        $response = $this->pushStockTake(
            $token,
            $tenantId,
            $stockTakeId,
            'in_progress',
            'Recount aisle 3 — quantities look off.'
        );
        $response->assertOk();

        $this->assertDatabaseHas('stock_takes', [
            'id' => $stockTakeId,
            'status' => 'in_progress',
            'review_comment' => 'Recount aisle 3 — quantities look off.',
        ]);
    }

    public function test_cannot_skip_straight_from_draft_to_approved(): void
    {
        $tenantId = 'tenant-stocktake-2';
        $token = $this->actingDeviceToken($tenantId);
        $stockTakeId = (string) Str::uuid();

        $this->pushStockTake($token, $tenantId, $stockTakeId, 'draft')->assertOk();

        $response = $this->pushStockTake($token, $tenantId, $stockTakeId, 'approved');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('stock_takes', [
            'id' => $stockTakeId,
            'status' => 'draft',
        ]);
    }

    public function test_a_rejected_stock_take_cannot_be_reopened(): void
    {
        $tenantId = 'tenant-stocktake-3';
        $token = $this->actingDeviceToken($tenantId);
        $stockTakeId = (string) Str::uuid();

        $this->pushStockTake($token, $tenantId, $stockTakeId, 'draft')->assertOk();
        $this->pushStockTake($token, $tenantId, $stockTakeId, 'in_progress')->assertOk();
        $this->pushStockTake($token, $tenantId, $stockTakeId, 'pending_approval')->assertOk();
        $this->pushStockTake($token, $tenantId, $stockTakeId, 'rejected', 'Too many discrepancies.')->assertOk();

        $response = $this->pushStockTake($token, $tenantId, $stockTakeId, 'in_progress');

        $response->assertOk();
        $response->assertJsonCount(1, 'errors');

        $this->assertDatabaseHas('stock_takes', [
            'id' => $stockTakeId,
            'status' => 'rejected',
        ]);
    }
}
