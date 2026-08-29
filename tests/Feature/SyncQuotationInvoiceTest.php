<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\RecurringInvoiceSchedule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SyncQuotationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create(['business_id' => $tenantId, 'email' => $tenantId.'-owner@example.com']);
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

    private function push(string $token, string $table, string $uuid, array $payload, string $operation = 'upsert'): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => $table,
                    'uuid' => $uuid,
                    'operation' => $operation,
                    'payload' => $payload,
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_quotation_and_its_items_can_be_pushed_and_pulled(): void
    {
        $tenantId = 'tenant-quo-1';
        $token = $this->actingDeviceToken($tenantId);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Acme Contractors']);

        $quoteId = (string) Str::uuid();
        $response = $this->push($token, 'quotations', $quoteId, [
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'quote_number' => 'QUO-202608-001',
            'status' => 'draft',
            'total' => 500,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
        $response->assertOk();
        $response->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('quotations', ['id' => $quoteId, 'quote_number' => 'QUO-202608-001']);

        $itemId = (string) Str::uuid();
        $itemResponse = $this->push($token, 'quotation_items', $itemId, [
            'quotation_id' => $quoteId,
            'business_id' => $tenantId,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Cement 50kg',
            'quantity' => 10,
            'unit_price' => 50,
            'line_total' => 500,
        ]);
        $itemResponse->assertOk();
        $itemResponse->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('quotation_items', ['id' => $itemId, 'quotation_id' => $quoteId]);
    }

    public function test_quotation_status_transitions_and_deletion(): void
    {
        $tenantId = 'tenant-quo-2';
        $token = $this->actingDeviceToken($tenantId);
        $quote = Quotation::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId,
            'customer_id' => (string) Str::uuid(), 'quote_number' => 'QUO-202608-002', 'status' => 'draft',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $accept = $this->push($token, 'quotations', $quote->id, [
            'business_id' => $tenantId,
            'customer_id' => $quote->customer_id,
            'quote_number' => $quote->quote_number,
            'status' => 'accepted',
            'accepted_at' => now()->toIso8601String(),
            'created_by_user_id' => $quote->created_by_user_id,
        ]);
        $accept->assertOk();
        $this->assertDatabaseHas('quotations', ['id' => $quote->id, 'status' => 'accepted']);

        $delete = $this->push($token, 'quotations', $quote->id, [], 'delete');
        $delete->assertOk();
        $this->assertDatabaseMissing('quotations', ['id' => $quote->id]);
    }

    public function test_invoice_with_items_and_payment_can_be_pushed(): void
    {
        $tenantId = 'tenant-inv-1';
        $token = $this->actingDeviceToken($tenantId);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Beta Hardware']);

        $invoiceId = (string) Str::uuid();
        $invResponse = $this->push($token, 'invoices', $invoiceId, [
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-202608-001',
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'payment_terms_days' => 30,
            'total' => 1000,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
        $invResponse->assertOk();
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'invoice_number' => 'INV-202608-001']);

        $itemId = (string) Str::uuid();
        $itemResponse = $this->push($token, 'invoice_items', $itemId, [
            'invoice_id' => $invoiceId,
            'business_id' => $tenantId,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Roofing sheets',
            'quantity' => 20,
            'unit_price' => 50,
            'line_total' => 1000,
        ]);
        $itemResponse->assertOk();
        $itemResponse->assertJsonCount(1, 'accepted');
        $this->assertDatabaseHas('invoice_items', ['id' => $itemId, 'invoice_id' => $invoiceId]);

        $paymentId = (string) Str::uuid();
        $payResponse = $this->push($token, 'invoice_payments', $paymentId, [
            'invoice_id' => $invoiceId,
            'method' => 'bank_transfer',
            'amount' => 400,
            'currency_code' => 'USD',
            'base_equivalent' => 400,
            'recorded_by_user_id' => (string) Str::uuid(),
            'paid_at' => now()->toIso8601String(),
        ]);
        $payResponse->assertOk();
        $this->assertDatabaseHas('invoice_payments', ['id' => $paymentId, 'amount' => 400]);

        // Invoice payments are an append-only ledger — deletes are ignored.
        $deleteAttempt = $this->push($token, 'invoice_payments', $paymentId, [], 'delete');
        $deleteAttempt->assertOk();
        $this->assertDatabaseHas('invoice_payments', ['id' => $paymentId]);
    }

    public function test_credit_note_can_be_pushed_and_posts_against_an_invoice(): void
    {
        $tenantId = 'tenant-cn-1';
        $token = $this->actingDeviceToken($tenantId);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Gamma Traders']);
        $invoice = Invoice::create([
            'id' => (string) Str::uuid(), 'business_id' => $tenantId, 'customer_id' => $customer->id,
            'invoice_number' => 'INV-202608-002', 'status' => 'paid', 'issue_date' => now(), 'total' => 200,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $creditNoteId = (string) Str::uuid();
        $response = $this->push($token, 'credit_notes', $creditNoteId, [
            'business_id' => $tenantId,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'credit_note_number' => 'CN-202608-001',
            'reason' => 'Damaged goods returned',
            'total' => 50,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
        $response->assertOk();
        $this->assertDatabaseHas('credit_notes', ['id' => $creditNoteId, 'invoice_id' => $invoice->id]);
    }

    public function test_recurring_invoice_schedule_can_be_pushed(): void
    {
        $tenantId = 'tenant-rec-1';
        $token = $this->actingDeviceToken($tenantId);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Delta Supplies']);
        $productId = (string) Str::uuid();

        $scheduleId = (string) Str::uuid();
        $response = $this->push($token, 'recurring_invoice_schedules', $scheduleId, [
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'template_json' => ['items' => [['product_id' => $productId, 'quantity' => 1, 'unit_price' => 100]]],
            'frequency' => 'monthly',
            'next_run_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
            'created_by_user_id' => (string) Str::uuid(),
        ]);
        $response->assertOk();
        $this->assertDatabaseHas('recurring_invoice_schedules', ['id' => $scheduleId, 'frequency' => 'monthly']);

        $schedule = RecurringInvoiceSchedule::find($scheduleId);
        $this->assertSame($productId, $schedule->template_json['items'][0]['product_id']);
        $this->assertSame(1, $schedule->template_json['items'][0]['quantity']);
    }

    public function test_device_cannot_hijack_another_businesss_quotation(): void
    {
        $victimTenant = 'tenant-quo-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimQuote = Quotation::create([
            'id' => (string) Str::uuid(), 'business_id' => $victimTenant,
            'customer_id' => (string) Str::uuid(), 'quote_number' => 'QUO-VICTIM-001', 'status' => 'draft',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $attackerToken = $this->actingDeviceToken('tenant-quo-attacker');

        $response = $this->push($attackerToken, 'quotations', $victimQuote->id, [
            'business_id' => 'tenant-quo-attacker',
            'customer_id' => (string) Str::uuid(),
            'quote_number' => 'HIJACKED',
            'status' => 'accepted',
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
        $this->assertDatabaseHas('quotations', ['id' => $victimQuote->id, 'quote_number' => 'QUO-VICTIM-001']);
    }

    public function test_device_cannot_attach_an_invoice_item_to_another_businesss_invoice(): void
    {
        $victimTenant = 'tenant-inv-victim';
        Tenant::create(['id' => $victimTenant, 'business_name' => $victimTenant, 'owner_email' => $victimTenant.'@example.com']);
        $victimInvoice = Invoice::create([
            'id' => (string) Str::uuid(), 'business_id' => $victimTenant,
            'customer_id' => (string) Str::uuid(), 'invoice_number' => 'INV-VICTIM-001',
            'status' => 'draft', 'issue_date' => now(), 'total' => 0,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $attackerToken = $this->actingDeviceToken('tenant-inv-attacker');

        $response = $this->push($attackerToken, 'invoice_items', (string) Str::uuid(), [
            'invoice_id' => $victimInvoice->id,
            'business_id' => 'tenant-inv-attacker',
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Free stuff',
            'quantity' => 999,
            'unit_price' => 0,
            'line_total' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonCount(0, 'accepted');
        $response->assertJsonCount(1, 'errors');
    }
}
