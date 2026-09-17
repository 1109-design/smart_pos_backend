<?php

namespace Tests\Feature;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Security audit follow-up: the salary_payments/supplier_payments/assets
 * escalation guards each check the *higher-level* table (a device pushing
 * a fabricated salary_payments row, say). But journal_headers is the
 * shared low-level table every one of those postings actually writes to —
 * a modified client could skip the higher-level table entirely and push a
 * journal_headers row with source_type: 'salary_payment' directly,
 * sidestepping that guard completely while landing the exact same
 * fraudulent GL entry. This proves the direct-bypass path is now closed
 * too, without breaking the same source_type used legitimately elsewhere
 * (a cashier's own 'sale' journal is untouched).
 */
class SyncJournalHeaderSourceEscalationGuardTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithChartOfAccounts(string $tenantId): void
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function actingDeviceToken(string $tenantId, User $user): string
    {
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

    private function pushJournalHeader(string $token, string $tenantId, string $sourceType): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'journal_headers',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'journal_number' => 'JV-000001',
                        'trans_date' => now()->toDateString(),
                        'source_type' => $sourceType,
                        'source_id' => (string) Str::uuid(),
                        'status' => 'posted',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_a_cashier_cannot_bypass_the_salary_payments_guard_by_posting_the_journal_directly(): void
    {
        $tenantId = 'tenant-journal-bypass-salary';
        $this->tenantWithChartOfAccounts($tenantId);

        $cashier = User::factory()->create(['email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushJournalHeader($token, $tenantId, 'salary_payment');

        $response->assertOk();
        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(
            'journal_headers: posting a salary_payment journal requires owner or manager access.',
            $response->json('errors.0.reason'),
        );
        $this->assertSame(0, JournalHeader::where('business_id', $tenantId)->count());
    }

    public function test_a_manager_cannot_bypass_the_asset_owner_only_guard_by_posting_the_journal_directly(): void
    {
        $tenantId = 'tenant-journal-bypass-asset';
        $this->tenantWithChartOfAccounts($tenantId);

        $manager = User::factory()->create(['email' => $tenantId.'-manager@example.com']);
        $manager->assignRole('manager');
        $token = $this->actingDeviceToken($tenantId, $manager);

        $response = $this->pushJournalHeader($token, $tenantId, 'asset_acquisition');

        $this->assertCount(0, $response->json('accepted'));
        $this->assertSame(0, JournalHeader::where('business_id', $tenantId)->count());
    }

    public function test_a_cashiers_own_sale_journal_is_unaffected(): void
    {
        $tenantId = 'tenant-journal-sale-unaffected';
        $this->tenantWithChartOfAccounts($tenantId);

        $cashier = User::factory()->create(['email' => $tenantId.'-cashier@example.com']);
        $cashier->assignRole('cashier');
        $token = $this->actingDeviceToken($tenantId, $cashier);

        $response = $this->pushJournalHeader($token, $tenantId, 'sale');

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
        $this->assertSame(1, JournalHeader::where('business_id', $tenantId)->where('source_type', 'sale')->count());
    }

    public function test_the_business_owner_can_legitimately_post_a_cash_vault_journal(): void
    {
        $tenantId = 'tenant-journal-vault-owner';
        $this->tenantWithChartOfAccounts($tenantId);

        $owner = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $owner->assignRole('business_owner');
        $token = $this->actingDeviceToken($tenantId, $owner);

        $response = $this->pushJournalHeader($token, $tenantId, 'cash_vault_drop');

        $response->assertOk();
        $this->assertCount(1, $response->json('accepted'));
    }
}
