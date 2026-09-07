<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * End-to-end coverage through the real /api/v1/sync/push endpoint — proving
 * a till syncing a salary payment (exactly what employees_screen.dart's
 * payroll flow sends) triggers SalaryPostingService via SyncProcessor's
 * 'salary_payments' case, not just the service in isolation (already
 * covered by SalaryPostingServiceTest).
 */
class SyncTriggersSalaryPostingTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($tenantId);

        $user = User::factory()->create(['email' => $tenantId.'-owner@example.com']);
        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_a_synced_salary_payment_posts_wages_against_cash(): void
    {
        $tenantId = 'tenant-e2e-payroll';
        $token = $this->actingDeviceToken($tenantId);

        $employee = Employee::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Jane Worker']);

        // Fund cash first — must_be_positive on 1000 would otherwise reject
        // paying wages out of an account with no balance, same reasoning as
        // SalaryPostingServiceTest's fund() helper.
        $journals = app(JournalService::class);
        $header = $journals->createDraft($tenantId, '2026-01-01', 'capital', (string) Str::uuid());
        $cash = GlAccount::where('business_id', $tenantId)->where('code', '1000')->first();
        $capital = GlAccount::where('business_id', $tenantId)->where('code', '3000')->first();
        $journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 1000]);
        $journals->addLine($header, ['gl_account_id' => $capital->id, 'credit' => 1000]);
        $journals->post($header);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'salary_payments',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'employee_id' => $employee->id,
                        'period' => '2026-08',
                        'amount' => 250,
                        'currency_code' => 'USD',
                        'base_equivalent' => 250,
                        'exchange_rate' => 1,
                        'payment_method' => 'cash',
                        'paid_by_user_id' => (string) Str::uuid(),
                        'paid_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');

        $journal = JournalHeader::where('source_type', 'salary_payment')->where('business_id', $tenantId)->first();
        $this->assertNotNull($journal, 'a GL entry should have been posted for the salary payment');
        $this->assertSame('posted', $journal->status);

        $wages = GlAccount::where('business_id', $tenantId)->where('code', '6020')->first();
        $this->assertSame(250.0, $wages->balance());
        $this->assertSame(750.0, $cash->fresh()->balance());
    }
}
