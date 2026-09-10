<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\CreditTransaction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Invoices-on-account are unified with the existing POS credit-sale ledger:
 * issuing an invoice posts a `credit_transactions` row of type 'invoice',
 * and paying it off posts a 'repayment' row carrying a `receipt_number`.
 * Both should flow through the same generic path every other
 * credit_transactions type already exercises — recomputeCustomerBalances()
 * sums the whole table regardless of `type`, so no dedicated invoice
 * recompute logic is needed.
 */
class SyncInvoiceLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
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

    public function test_issuing_an_invoice_bumps_customer_credit_balance(): void
    {
        $tenantId = 'tenant-invoice-issue';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Jane Doe', 'credit_limit' => 1000]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'credit_transactions',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => [
                        'customer_id' => $customerId,
                        'transaction_id' => null,
                        'amount' => 250,
                        'type' => 'invoice',
                        'method' => null,
                        'reference' => 'INV-202609-001',
                        'receipt_number' => null,
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'credit_balance' => 250]);
    }

    public function test_paying_an_invoice_posts_a_repayment_with_a_receipt_number_and_reduces_balance(): void
    {
        $tenantId = 'tenant-invoice-payment';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Jane Doe']);

        // credit_balance is always derived from the full ledger, never taken
        // at face value from the till — so establish the invoice debit first,
        // the same way the till itself would have (post-issue) before the
        // customer ever pays anything.
        CreditTransaction::create([
            'id' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'amount' => 250,
            'type' => 'invoice',
            'reference' => 'INV-202609-001',
        ]);

        $txnId = (string) Str::uuid();
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'credit_transactions',
                    'uuid' => $txnId,
                    'operation' => 'upsert',
                    'payload' => [
                        'customer_id' => $customerId,
                        'transaction_id' => null,
                        'amount' => -100,
                        'type' => 'repayment',
                        'method' => 'Cash',
                        'reference' => 'INV-202609-001',
                        'receipt_number' => 'RCT-202609-001',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'credit_balance' => 150]);
        $this->assertDatabaseHas('credit_transactions', [
            'id' => $txnId,
            'receipt_number' => 'RCT-202609-001',
            'reference' => 'INV-202609-001',
        ]);
    }
}
