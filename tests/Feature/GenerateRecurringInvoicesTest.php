<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\RecurringInvoiceSchedule;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class GenerateRecurringInvoicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_due_active_schedule_generates_an_invoice_and_advances_next_run_date(): void
    {
        $tenantId = 'tenant-recur-1';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Regular Trade Account']);
        $productId = (string) Str::uuid();

        $schedule = RecurringInvoiceSchedule::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'template_json' => [
                'items' => [
                    ['product_id' => $productId, 'product_name' => 'Monthly service fee', 'quantity' => 1, 'unit_price' => 250, 'discount_pct' => 0],
                ],
                'payment_terms_days' => 14,
            ],
            'frequency' => 'monthly',
            'next_run_date' => Carbon::today(),
            'is_active' => true,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->artisan('invoices:generate-recurring')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 1);
        $invoice = Invoice::first();
        $this->assertSame($tenantId, $invoice->business_id);
        $this->assertSame($customer->id, $invoice->customer_id);
        $this->assertSame(250.0, (float) $invoice->total);
        $this->assertSame($schedule->id, $invoice->recurring_schedule_id);
        $this->assertSame(14.0, $invoice->issue_date->diffInDays($invoice->due_date));

        $this->assertDatabaseCount('invoice_items', 1);

        $schedule->refresh();
        $this->assertSame($invoice->id, $schedule->last_generated_invoice_id);
        $this->assertTrue($schedule->next_run_date->isSameDay(Carbon::today()->addMonth()));

        // The generated invoice and its advanced schedule both land in the
        // sync outbox so devices pick them up on their next pull.
        $this->assertDatabaseHas('sync_records', ['table_name' => 'invoices', 'record_uuid' => $invoice->id]);
        $this->assertDatabaseHas('sync_records', ['table_name' => 'recurring_invoice_schedules', 'record_uuid' => $schedule->id]);
    }

    public function test_a_schedule_not_yet_due_is_skipped(): void
    {
        $tenantId = 'tenant-recur-2';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Not Due Yet']);

        RecurringInvoiceSchedule::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'template_json' => ['items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_price' => 100]]],
            'frequency' => 'monthly',
            'next_run_date' => Carbon::tomorrow(),
            'is_active' => true,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->artisan('invoices:generate-recurring')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_an_inactive_due_schedule_is_skipped(): void
    {
        $tenantId = 'tenant-recur-3';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Paused Account']);

        RecurringInvoiceSchedule::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'template_json' => ['items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_price' => 100]]],
            'frequency' => 'monthly',
            'next_run_date' => Carbon::today(),
            'is_active' => false,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->artisan('invoices:generate-recurring')->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_dry_run_generates_nothing(): void
    {
        $tenantId = 'tenant-recur-4';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Dry Run Account']);

        RecurringInvoiceSchedule::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'template_json' => ['items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_price' => 100]]],
            'frequency' => 'weekly',
            'next_run_date' => Carbon::today(),
            'is_active' => true,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->artisan('invoices:generate-recurring', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_weekly_frequency_advances_by_one_week(): void
    {
        $tenantId = 'tenant-recur-5';
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $customer = Customer::create(['id' => (string) Str::uuid(), 'business_id' => $tenantId, 'name' => 'Weekly Account']);

        $schedule = RecurringInvoiceSchedule::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'customer_id' => $customer->id,
            'template_json' => ['items' => [['product_id' => (string) Str::uuid(), 'quantity' => 1, 'unit_price' => 20]]],
            'frequency' => 'weekly',
            'next_run_date' => Carbon::today(),
            'is_active' => true,
            'created_by_user_id' => (string) Str::uuid(),
        ]);

        $this->artisan('invoices:generate-recurring')->assertSuccessful();

        $schedule->refresh();
        $this->assertTrue($schedule->next_run_date->isSameDay(Carbon::today()->addWeek()));
    }
}
