<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
use App\Models\PendingSyncRecord;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Master spec section 13 ("out-of-order synchronization"): four causally
 * dependent events — an invoice (A), its line item (B), and two payments
 * against it (C, D) — created offline and pushed to the server in a
 * scrambled order (C, A, D, B) across FOUR SEPARATE push requests, the way
 * a real device's outbox would drain them (retries, reordering by network
 * conditions, or simply because the outbox isn't causally sorted).
 *
 * FIXED BEHAVIOR (previously a documented gap): a child record whose parent
 * doesn't exist yet is no longer a hard processing_error. SyncProcessor now
 * throws the distinct MissingParentRecordException (vs. a genuine ownership
 * mismatch, which still hard-fails), and SyncController::push() catches it
 * to queue the record into `pending_sync_records` instead, reporting it back
 * as `deferred`. Every push response then calls resolvePendingRecords() for
 * the business, which replays any queued record whose dependency has since
 * landed — including this same request's own newly-created parent. So a
 * server that never hears from the client again about the deferred payment
 * still self-heals the moment the invoice arrives, with no client retry
 * required.
 */
class SyncOutOfOrderEventsTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

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

    public function test_scrambled_arrival_order_defers_the_premature_child_then_self_heals_once_its_parent_lands(): void
    {
        $tenantId = 'tenant-out-of-order';
        $token = $this->actingDeviceToken($tenantId);

        $customerId = (string) Str::uuid();
        Customer::create(['id' => $customerId, 'business_id' => $tenantId, 'name' => 'Out Of Order Customer']);

        $productId = (string) Str::uuid();
        Product::create(['id' => $productId, 'business_id' => $tenantId, 'name' => 'Consulting hours', 'price' => 100]);

        $invoiceId = (string) Str::uuid();
        $itemId = (string) Str::uuid();
        $paymentCId = (string) Str::uuid();
        $paymentDId = (string) Str::uuid();
        $now = now()->toIso8601String();

        $push = fn (string $table, string $uuid, array $payload) => $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => $table,
                'uuid' => $uuid,
                'operation' => 'upsert',
                'payload' => $payload,
                'updated_at' => $now,
            ]]]);

        // Event A: the invoice itself.
        $eventA = fn () => $push('invoices', $invoiceId, [
            'business_id' => $tenantId,
            'customer_id' => $customerId,
            'invoice_number' => 'INV-OOO-001',
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
            'subtotal' => 100,
            'tax_total' => 0,
            'total' => 100,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        // Event B: the invoice's one line item.
        $eventB = fn () => $push('invoice_items', $itemId, [
            'invoice_id' => $invoiceId,
            'product_id' => $productId,
            'product_name' => 'Consulting hours',
            'quantity' => 1,
            'unit_price' => 100,
            'line_total' => 100,
        ]);

        // Event C: a $60 payment against the invoice.
        $eventC = fn () => $push('invoice_payments', $paymentCId, [
            'invoice_id' => $invoiceId,
            'method' => 'cash',
            'amount' => 60,
            'currency_code' => 'USD',
            'base_equivalent' => 60,
            'recorded_by_user_id' => (string) Str::uuid(),
            'paid_at' => $now,
        ]);

        // Event D: the remaining $40 payment.
        $eventD = fn () => $push('invoice_payments', $paymentDId, [
            'invoice_id' => $invoiceId,
            'method' => 'cash',
            'amount' => 40,
            'currency_code' => 'USD',
            'base_equivalent' => 40,
            'recorded_by_user_id' => (string) Str::uuid(),
            'paid_at' => $now,
        ]);

        // --- Scrambled arrival: C, A, D, B ---------------------------------

        // C first: its parent invoice doesn't exist yet anywhere on the
        // server. Fixed behavior: this is deferred, not rejected.
        $respC1 = $eventC();
        $respC1->assertOk();
        $this->assertCount(0, $respC1->json('accepted'), 'a payment for a not-yet-existing invoice must not be silently accepted');
        $this->assertCount(0, $respC1->json('errors'), 'a premature child push must not surface as a hard error');
        $this->assertCount(1, $respC1->json('deferred'), 'it must be queued for automatic replay once its dependency lands');
        $this->assertStringContainsString(
            'referenced parent does not exist yet',
            $respC1->json('deferred.0.reason'),
        );
        $this->assertSame(0, InvoicePayment::where('id', $paymentCId)->count(), 'the deferred push must not have partially applied');
        $this->assertSame(1, PendingSyncRecord::where('record_uuid', $paymentCId)->count(), 'must be persisted in the pending queue, not lost');

        // A next: the invoice now exists. This push's own resolvePendingRecords()
        // pass must immediately replay C — no client retry needed.
        $respA = $eventA();
        $respA->assertOk();
        $this->assertCount(1, $respA->json('accepted'));
        $this->assertSame(1, Invoice::where('id', $invoiceId)->count());
        $this->assertCount(1, $respA->json('resolved_pending'), 'landing the parent must auto-resolve the payment that was waiting on it');
        $this->assertSame($paymentCId, $respA->json('resolved_pending.0.uuid'));
        $this->assertSame(1, InvoicePayment::where('id', $paymentCId)->count(), 'C must now exist without the client ever resubmitting it');
        $this->assertSame(0, PendingSyncRecord::where('record_uuid', $paymentCId)->count(), 'resolved pending row must be cleared, not left queued');

        // D next: its parent now exists, so it succeeds on first attempt even
        // though it arrived "out of order" relative to a natural A,B,C,D sequence.
        $respD = $eventD();
        $respD->assertOk();
        $this->assertCount(1, $respD->json('accepted'));
        $this->assertEquals(100.0, Invoice::find($invoiceId)->fresh()->amount_paid, 'amount_paid must reflect C (already applied) + D');

        // B next: the line item, also now unblocked.
        $respB = $eventB();
        $respB->assertOk();
        $this->assertCount(1, $respB->json('accepted'));
        $this->assertSame(1, InvoiceItem::where('id', $itemId)->count());

        // --- Final state: correct regardless of the scrambled original order ---
        $invoice = Invoice::find($invoiceId)->fresh();
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoiceId)->count(), 'both C and D must be present, exactly once each');
        $this->assertEquals(100.0, $invoice->amount_paid, 'fully paid: 60 (C) + 40 (D), regardless of arrival order');
        $this->assertSame(1, InvoiceItem::where('invoice_id', $invoiceId)->count());
        $this->assertEquals(100.0, InvoiceItem::where('invoice_id', $invoiceId)->first()->line_total);
    }

    public function test_a_genuine_cross_tenant_ownership_mismatch_still_hard_fails_and_is_not_deferred(): void
    {
        $tenantId = 'tenant-ooo-security';
        $token = $this->actingDeviceToken($tenantId);

        $otherTenantId = 'tenant-ooo-other';
        Tenant::create(['id' => $otherTenantId, 'business_name' => $otherTenantId, 'owner_email' => $otherTenantId.'@example.com']);
        $otherCustomerId = (string) Str::uuid();
        Customer::create(['id' => $otherCustomerId, 'business_id' => $otherTenantId, 'name' => 'Other Tenant Customer']);
        $otherInvoiceId = (string) Str::uuid();
        Invoice::create([
            'id' => $otherInvoiceId,
            'business_id' => $otherTenantId,
            'customer_id' => $otherCustomerId,
            'invoice_number' => 'INV-OTHER-001',
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
            'subtotal' => 50,
            'tax_total' => 0,
            'total' => 50,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        // A payment referencing an invoice that DOES exist, but belongs to a
        // different business — this must still hard-fail (it's a real
        // ownership violation, not a missing dependency) and must NOT be
        // queued into pending_sync_records.
        $resp = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'invoice_payments',
                'uuid' => (string) Str::uuid(),
                'operation' => 'upsert',
                'payload' => [
                    'invoice_id' => $otherInvoiceId,
                    'method' => 'cash',
                    'amount' => 10,
                    'currency_code' => 'USD',
                    'base_equivalent' => 10,
                    'recorded_by_user_id' => (string) Str::uuid(),
                    'paid_at' => now()->toIso8601String(),
                ],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        $resp->assertOk();
        $this->assertCount(0, $resp->json('deferred'), 'a genuine cross-tenant reference must not be treated as merely out-of-order');
        $this->assertCount(1, $resp->json('errors'));
        $this->assertStringContainsString('does not belong to this business', $resp->json('errors.0.reason'));
        $this->assertSame(0, PendingSyncRecord::count());
    }

    public function test_a_permanently_orphaned_record_stays_queued_and_visible_rather_than_lost(): void
    {
        $tenantId = 'tenant-ooo-orphan';
        $token = $this->actingDeviceToken($tenantId);

        $orphanPaymentId = (string) Str::uuid();
        $neverArrivesInvoiceId = (string) Str::uuid();

        $push = fn () => $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'invoice_payments',
                'uuid' => $orphanPaymentId,
                'operation' => 'upsert',
                'payload' => [
                    'invoice_id' => $neverArrivesInvoiceId,
                    'method' => 'cash',
                    'amount' => 25,
                    'currency_code' => 'USD',
                    'base_equivalent' => 25,
                    'recorded_by_user_id' => (string) Str::uuid(),
                    'paid_at' => now()->toIso8601String(),
                ],
                'updated_at' => now()->toIso8601String(),
            ]]]);

        // First push defers it.
        $push()->assertOk();
        $this->assertSame(1, PendingSyncRecord::where('record_uuid', $orphanPaymentId)->count());

        // Push ten more unrelated requests (each one re-attempts every
        // pending row for the business via resolvePendingRecords()); the
        // invoice never arrives, so the row must eventually be marked
        // failed_permanently rather than retried forever or silently dropped.
        for ($i = 0; $i < 10; $i++) {
            $this->withHeader('Authorization', 'Bearer '.$token)
                ->postJson('/api/v1/sync/push', ['records' => [[
                    'table' => 'customers',
                    'uuid' => (string) Str::uuid(),
                    'operation' => 'upsert',
                    'payload' => ['business_id' => $tenantId, 'name' => 'Filler Customer '.$i],
                    'updated_at' => now()->toIso8601String(),
                ]]])->assertOk();
        }

        $row = PendingSyncRecord::where('record_uuid', $orphanPaymentId)->first();
        $this->assertNotNull($row, 'must still be visible for reconciliation, not deleted');
        $this->assertTrue($row->failed_permanently, 'must stop retrying forever once the attempt cap is hit');
        $this->assertGreaterThanOrEqual(10, $row->attempts);
    }
}
