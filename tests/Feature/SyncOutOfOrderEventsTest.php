<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Device;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoicePayment;
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
 * CURRENT BEHAVIOR (a real, documented gap — not fixed by this test): this
 * codebase does NOT tolerate a child record arriving before its parent.
 * SyncProcessor::resolveParentOwner() (app/Services/SyncProcessor.php:367)
 * looks up the parent's business_id to authorize the child; if the parent
 * doesn't exist yet, assertOwnership() (SyncProcessor.php:343-345) throws
 * "referenced parent does not belong to this business." — a
 * processing_error, not a deferred/queued state. So pushing C (a payment)
 * before A (its invoice) fails outright on first attempt. There is no
 * server-side dependency graph / deferred-apply queue: the client's outbox
 * is entirely responsible for either sequencing pushes correctly or
 * detecting the error and re-queueing the failed record for a later retry
 * (which sync_service.dart's bounded-retry queue does support, per the
 * earlier architecture audit — so this degrades to "eventually consistent
 * after N retries," not silent data loss, but it is NOT "order-independent"
 * as master spec section 13 asks for).
 *
 * This test proves both halves: (1) the failure is real and specific
 * (asserted below), and (2) once each record's dependency has actually
 * landed and the client retries the ones that failed, the final state is
 * fully correct regardless of the original scrambled arrival order.
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

    public function test_scrambled_arrival_order_fails_the_premature_child_then_converges_correctly_after_retry(): void
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
        // server. This is the documented gap — it fails, it does not queue
        // itself server-side for later.
        $respC1 = $eventC();
        $respC1->assertOk();
        $this->assertCount(0, $respC1->json('accepted'), 'a payment for a not-yet-existing invoice must not be silently accepted');
        $this->assertCount(1, $respC1->json('errors'), 'CURRENT BEHAVIOR: premature child push is a hard processing_error, not a deferred/queued state');
        $this->assertStringContainsString(
            'does not belong to this business',
            $respC1->json('errors.0.reason'),
            'failure must be the parent-ownership resolution failing, not something unrelated'
        );
        $this->assertSame(0, InvoicePayment::where('id', $paymentCId)->count(), 'the failed push must not have partially applied');

        // A next: the invoice now exists.
        $respA = $eventA();
        $respA->assertOk();
        $this->assertCount(1, $respA->json('accepted'));
        $this->assertSame(1, Invoice::where('id', $invoiceId)->count());

        // D next: its parent now exists, so — unlike C a moment ago — it
        // succeeds on first attempt even though it arrived "out of order"
        // relative to a natural A,B,C,D sequence.
        $respD = $eventD();
        $respD->assertOk();
        $this->assertCount(1, $respD->json('accepted'));
        $this->assertEquals(40.0, Invoice::find($invoiceId)->fresh()->amount_paid, 'amount_paid must reflect D alone at this point');

        // B next: the line item, also now unblocked.
        $respB = $eventB();
        $respB->assertOk();
        $this->assertCount(1, $respB->json('accepted'));
        $this->assertSame(1, InvoiceItem::where('id', $itemId)->count());

        // Finally, the client's outbox does what a real retry loop does:
        // re-sends the record that previously came back in `errors`. Same
        // uuid, same payload — this is not a new event, it's C's retry.
        $respC2 = $eventC();
        $respC2->assertOk();
        $this->assertCount(1, $respC2->json('accepted'), 'C must succeed now that its dependency exists');
        $this->assertCount(0, $respC2->json('errors'));

        // --- Final state: correct regardless of the scrambled original order ---
        $invoice = Invoice::find($invoiceId)->fresh();
        $this->assertSame(2, InvoicePayment::where('invoice_id', $invoiceId)->count(), 'both C and D must be present, exactly once each');
        $this->assertEquals(100.0, $invoice->amount_paid, 'fully paid: 60 (C) + 40 (D), regardless of arrival order');
        $this->assertSame(1, InvoiceItem::where('invoice_id', $invoiceId)->count());
        $this->assertEquals(100.0, InvoiceItem::where('invoice_id', $invoiceId)->first()->line_total);
    }
}
