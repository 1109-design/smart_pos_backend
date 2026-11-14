<?php

namespace Tests\Feature;

use App\Jobs\ProcessZimraFiscalisationJob;
use App\Models\Business;
use App\Models\CreditNote;
use App\Models\Device;
use App\Models\Location;
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

    private function pushTransaction(string $token, string $tenantId, string $transactionId, ?string $locationId = null): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $transactionId,
                    'operation' => 'upsert',
                    'payload' => [
                        'business_id' => $tenantId,
                        'location_id' => $locationId,
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

    public function test_sale_fiscalises_through_its_own_branchs_device_not_another_branchs(): void
    {
        // Regression for the "always picks ZimraDevice::first()" bug: a chain
        // with one registered device per branch must fiscalise each sale
        // through its own branch's device, never whichever device happens to
        // be first for the tenant.
        Queue::fake();
        $tenantId = 'tenant-fiscal-multi-branch';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Multi Branch Hardware',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        $branchA = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Branch A',
            'type' => 'shop',
            'is_active' => true,
        ]);
        $branchB = Location::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'name' => 'Branch B',
            'type' => 'shop',
            'is_active' => true,
        ]);

        // Device A registered first — this is the row that ->first() used to
        // return for every sale regardless of branch.
        ZimraDevice::create([
            'business_id' => $tenantId,
            'location_id' => $branchA->id,
            'tin' => '1234567890',
            'device_id' => '90001',
            'is_active' => true,
            'status' => 'active',
        ]);
        ZimraDevice::create([
            'business_id' => $tenantId,
            'location_id' => $branchB->id,
            'tin' => '1234567890',
            'device_id' => '90002',
            'is_active' => true,
            'status' => 'active',
        ]);

        $saleAtBranchB = (string) Str::uuid();
        $this->pushTransaction($token, $tenantId, $saleAtBranchB, $branchB->id)->assertOk();

        $this->assertDatabaseHas('zimra_sales', [
            'transaction_id' => $saleAtBranchB,
            'device_id' => '90002',
        ]);
    }

    public function test_sale_excluded_by_cashier_is_never_queued(): void
    {
        Queue::fake();
        $tenantId = 'tenant-fiscal-excluded';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Excluded Sale Shop',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        ZimraDevice::create([
            'business_id' => $tenantId,
            'tin' => '1234567890',
            'device_id' => '90004',
            'is_active' => true,
            'status' => 'active',
        ]);

        $transactionId = (string) Str::uuid();
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
                        'tax_total' => 0,
                        'total' => 10,
                        'base_currency' => 'USD',
                        'status' => 'completed',
                        'sale_number' => '202607-TEST-EXCLUDED',
                        'fiscalisation_requested' => false,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseCount('zimra_sales', 0);
        Queue::assertNotPushed(ProcessZimraFiscalisationJob::class);

        $this->assertDatabaseHas('transactions', [
            'id' => $transactionId,
            'fiscal_status' => 'excluded',
            'fiscalisation_requested' => false,
        ]);
    }

    public function test_sale_with_no_location_falls_back_to_business_wide_device(): void
    {
        // Backward compatibility: a business that hasn't split its fiscal
        // device by branch (location_id null on the device) keeps working
        // even for sales that carry no location_id either.
        Queue::fake();
        $tenantId = 'tenant-fiscal-fallback';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Single Device Shop',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        ZimraDevice::create([
            'business_id' => $tenantId,
            'location_id' => null,
            'tin' => '1234567890',
            'device_id' => '90003',
            'is_active' => true,
            'status' => 'active',
        ]);

        $transactionId = (string) Str::uuid();
        $this->pushTransaction($token, $tenantId, $transactionId)->assertOk();

        $this->assertDatabaseHas('zimra_sales', [
            'transaction_id' => $transactionId,
            'device_id' => '90003',
        ]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function fiscalShop(string $tenantId, string $deviceId): array
    {
        $token = $this->actingDeviceToken($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Shop', 'fiscalisation_enabled' => true, 'tin' => '1234567890']);
        ZimraDevice::create(['business_id' => $tenantId, 'tin' => '1234567890', 'device_id' => $deviceId, 'is_active' => true, 'status' => 'active']);

        return [$token, $tenantId];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pushRow(string $token, string $table, string $uuid, array $payload): void
    {
        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table,
            'uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function salePayload(string $tenantId, string $status, float $total): array
    {
        return [
            'business_id' => $tenantId,
            'user_id' => '99999999-9999-4999-9999-999999999999',
            'subtotal' => $total,
            'tax_total' => 0,
            'total' => $total,
            'base_currency' => 'USD',
            'status' => $status,
            'sale_number' => 'S-'.Str::random(6),
        ];
    }

    public function test_a_credit_sale_is_queued_once_and_not_again_when_it_is_paid_off(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-credit', '12350');
        $id = (string) Str::uuid();

        $this->pushRow($token, 'transactions', $id, $this->salePayload($tenantId, 'credit_sale', 40));
        $this->assertDatabaseHas('zimra_sales', ['transaction_id' => $id, 'status' => 'pending']);
        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);

        $this->pushRow($token, 'transactions', $id, $this->salePayload($tenantId, 'completed', 40));
        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function returnPayload(string $tenantId, string $originalId, string $reversalId): array
    {
        return [
            'business_id' => $tenantId,
            'return_number' => 'RET-1',
            'original_transaction_id' => $originalId,
            'return_transaction_id' => $reversalId,
            'outcome' => 'refund',
            'returned_value' => 40,
            'net_amount' => -40,
            'reason' => 'Faulty',
            'requested_by_user_id' => '99999999-9999-4999-9999-999999999999',
        ];
    }

    public function test_a_reversal_row_alone_queues_nothing_until_its_return_arrives(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-refund', '12351');
        $originalId = (string) Str::uuid();
        $reversalId = (string) Str::uuid();
        $this->pushRow($token, 'transactions', $originalId, $this->salePayload($tenantId, 'completed', 40));

        $this->pushRow($token, 'transactions', $reversalId, $this->salePayload($tenantId, 'refunded', -40));
        $this->assertDatabaseMissing('zimra_sales', ['transaction_id' => $reversalId]);

        $this->pushRow($token, 'transaction_items', (string) Str::uuid(), [
            'transaction_id' => $reversalId,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 1,
            'unit_price' => 40,
            'line_total' => 40,
        ]);
        $this->pushRow($token, 'sales_returns', (string) Str::uuid(), $this->returnPayload($tenantId, $originalId, $reversalId));

        $this->assertDatabaseHas('zimra_sales', ['transaction_id' => $reversalId, 'status' => 'pending']);
    }

    public function test_a_return_that_arrives_before_its_reversal_row_still_queues_it(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-order', '12352');
        $originalId = (string) Str::uuid();
        $reversalId = (string) Str::uuid();
        $this->pushRow($token, 'transactions', $originalId, $this->salePayload($tenantId, 'completed', 40));

        $this->pushRow($token, 'sales_returns', (string) Str::uuid(), $this->returnPayload($tenantId, $originalId, $reversalId));
        $this->pushRow($token, 'transactions', $reversalId, $this->salePayload($tenantId, 'refunded', -40));

        $this->assertDatabaseHas('zimra_sales', ['transaction_id' => $reversalId, 'status' => 'pending']);
    }

    public function test_marking_the_original_sale_refunded_does_not_send_a_credit_note_for_it(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-orig', '12353');
        $id = (string) Str::uuid();
        $this->pushRow($token, 'transactions', $id, $this->salePayload($tenantId, 'completed', 40));
        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);

        $this->pushRow($token, 'transactions', $id, $this->salePayload($tenantId, 'refunded', 40));

        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function invoicePayload(string $tenantId, string $status, array $extra = []): array
    {
        return array_merge([
            'business_id' => $tenantId,
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-'.Str::random(6),
            'status' => $status,
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
            'tax_total' => 15,
            'total' => 115,
            'created_by_user_id' => '99999999-9999-4999-9999-999999999999',
        ], $extra);
    }

    public function test_a_draft_invoice_is_queued_when_it_is_issued_and_not_when_it_is_paid(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-invoice', '12360');
        $id = (string) Str::uuid();

        $this->pushRow($token, 'invoices', $id, $this->invoicePayload($tenantId, 'draft'));
        $this->assertDatabaseMissing('zimra_sales', ['invoice_id' => $id]);

        $this->pushRow($token, 'invoices', $id, $this->invoicePayload($tenantId, 'sent', ['currency_code' => 'ZWG', 'exchange_rate' => 2]));
        $this->assertDatabaseHas('zimra_sales', ['invoice_id' => $id, 'status' => 'pending']);
        $this->assertDatabaseHas('invoices', ['id' => $id, 'currency_code' => 'ZWG', 'fiscal_status' => 'pending']);
        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);

        $this->pushRow($token, 'invoices', $id, $this->invoicePayload($tenantId, 'paid'));
        Queue::assertPushed(ProcessZimraFiscalisationJob::class, 1);
    }

    public function test_an_opening_balance_pushed_as_issued_is_not_sent(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-opening', '12361');
        $id = (string) Str::uuid();

        $this->pushRow($token, 'invoices', $id, $this->invoicePayload($tenantId, 'issued', ['notes' => 'Opening Balance: legacy']));

        $this->assertDatabaseMissing('zimra_sales', ['invoice_id' => $id]);
    }

    public function test_invoice_and_credit_note_lines_keep_their_tax(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-lines', '12362');
        $invoiceId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $noteId = (string) Str::uuid();
        $noteItemId = (string) Str::uuid();

        $this->pushRow($token, 'invoices', $invoiceId, $this->invoicePayload($tenantId, 'draft'));
        $this->pushRow($token, 'invoice_items', $itemId, ['invoice_id' => $invoiceId, 'business_id' => $tenantId, 'product_id' => (string) Str::uuid(), 'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_amount' => 15, 'line_total' => 115]);
        CreditNote::create(['id' => $noteId, 'business_id' => $tenantId, 'invoice_id' => $invoiceId, 'customer_id' => (string) Str::uuid(), 'credit_note_number' => 'CN-1', 'created_by_user_id' => (string) Str::uuid()]);
        $this->pushRow($token, 'credit_note_items', $noteItemId, ['credit_note_id' => $noteId, 'business_id' => $tenantId, 'product_id' => (string) Str::uuid(), 'product_name' => 'Widget', 'quantity' => 1, 'unit_price' => 100, 'tax_amount' => 15, 'line_total' => 115]);

        $this->assertDatabaseHas('invoice_items', ['id' => $itemId, 'tax_amount' => 15]);
        $this->assertDatabaseHas('credit_note_items', ['id' => $noteItemId, 'tax_amount' => 15]);
    }

    public function test_a_customers_tax_numbers_survive_an_update_that_does_not_carry_them(): void
    {
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-customer', '12363');
        $id = (string) Str::uuid();

        $this->pushRow($token, 'customers', $id, ['business_id' => $tenantId, 'name' => 'Acme', 'tax_number' => '2000123456', 'vat_number' => '220012345']);
        $this->pushRow($token, 'customers', $id, ['business_id' => $tenantId, 'name' => 'Acme Ltd']);

        $this->assertDatabaseHas('customers', ['id' => $id, 'name' => 'Acme Ltd', 'tax_number' => '2000123456', 'vat_number' => '220012345']);

        $this->pushRow($token, 'customers', $id, ['business_id' => $tenantId, 'name' => 'Acme Ltd', 'tax_number' => null]);

        $this->assertDatabaseHas('customers', ['id' => $id, 'tax_number' => null]);
    }

    public function test_an_invoice_line_with_no_product_is_kept(): void
    {
        Queue::fake();
        [$token, $tenantId] = $this->fiscalShop('tenant-fiscal-service-line', '12364');
        $invoiceId = (string) Str::uuid();
        $itemId = (string) Str::uuid();

        $this->pushRow($token, 'invoices', $invoiceId, $this->invoicePayload($tenantId, 'draft'));
        $this->pushRow($token, 'invoice_items', $itemId, ['invoice_id' => $invoiceId, 'business_id' => $tenantId, 'product_id' => null, 'product_name' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'tax_amount' => 15, 'line_total' => 115]);

        $this->assertDatabaseHas('invoice_items', ['id' => $itemId, 'product_name' => 'Consulting', 'product_id' => null]);
    }
}
