<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Business;
use App\Models\CreditNote;
use App\Models\CustomerReceiptAllocation;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Customer documents (Phase 5) post on the device; the server keeps the
 * invoice's amount paid in step with everything that settles it —
 * payments, receipts and deposits allocated to it, and the part of a
 * posted credit note that credits it.
 */
class CustomerDocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-customer-docs';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole('business_owner');
        $this->token = $user->createToken('t')->plainTextToken;
        Device::create([
            'tenant_id' => self::BIZ, 'name' => 'Till', 'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $this->token)[0], 'is_revoked' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $table, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table, 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk()->assertJsonPath('errors', []);
    }

    private function invoice(): string
    {
        $customerId = (string) Str::uuid();
        $this->push('customers', $customerId, ['business_id' => self::BIZ, 'name' => 'Acme']);
        $id = (string) Str::uuid();
        $this->push('invoices', $id, [
            'business_id' => self::BIZ, 'customer_id' => $customerId, 'invoice_number' => 'INV-1',
            'status' => 'sent', 'issue_date' => '2026-10-01', 'subtotal' => 100, 'tax_total' => 15, 'total' => 115,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        return $id;
    }

    public function test_a_deposit_allocated_by_a_device_settles_the_invoice(): void
    {
        $invoiceId = $this->invoice();
        $allocationId = (string) Str::uuid();
        $receiptId = (string) Str::uuid();

        $this->push('customer_receipt_allocations', $allocationId, [
            'business_id' => self::BIZ, 'receipt_id' => $receiptId, 'invoice_id' => $invoiceId,
            'allocated_amount' => 40, 'allocated_at' => now()->toIso8601String(),
        ]);

        $this->assertSame($receiptId, CustomerReceiptAllocation::findOrFail($allocationId)->customer_receipt_id);
        $this->assertEquals(40, (float) Invoice::findOrFail($invoiceId)->amount_paid);
    }

    public function test_a_posted_credit_note_credits_its_invoice(): void
    {
        $invoiceId = $this->invoice();
        $noteId = (string) Str::uuid();

        $this->push('credit_notes', $noteId, [
            'business_id' => self::BIZ, 'invoice_id' => $invoiceId, 'customer_id' => (string) Str::uuid(),
            'credit_note_number' => 'CN-1', 'subtotal' => 40, 'tax_total' => 6, 'total' => 46,
            'status' => 'posted', 'is_gl_posted' => true, 'unallocated_amount' => 0,
            'journal_header_id' => (string) Str::uuid(), 'created_by_user_id' => (string) Str::uuid(),
        ]);

        $note = CreditNote::findOrFail($noteId);
        $this->assertTrue((bool) $note->is_gl_posted);
        $this->assertEquals(46, (float) Invoice::findOrFail($invoiceId)->amount_paid);
    }

    public function test_new_customer_documents_belong_to_their_period_modules(): void
    {
        $this->assertSame('ar', AccountingPeriod::moduleFor('credit_note'));
        $this->assertSame('ar', AccountingPeriod::moduleFor('customer_debit_note'));
        $this->assertSame('stock', AccountingPeriod::moduleFor('delivery_note'));
    }
}
