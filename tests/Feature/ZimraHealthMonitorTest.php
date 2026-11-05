<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Models\Zimra\ZimraFiscalDay;
use App\Models\Zimra\ZimraSale;
use App\Services\Zimra\ZimraHealthMonitor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZimraHealthMonitorTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $subjects = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['zimra.failure_alert_emails' => ['owner@example.com'], 'mail.default' => 'array']);
        Business::create(['id' => 'biz-1', 'name' => 'Fiscal Shop', 'fiscalisation_enabled' => true, 'tin' => '1234567890']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeSale(array $attributes = [], string $createdAt = '-1 hour'): Transaction
    {
        $sale = Transaction::create(array_merge([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => 100,
            'total' => 115,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => 'S-'.Str::random(4),
            'fiscalisation_requested' => true,
        ], $attributes));
        DB::table('transactions')->where('id', $sale->id)->update(['created_at' => now()->modify($createdAt)]);

        return $sale->fresh();
    }

    private function makeReceipt(Transaction $sale, Carbon $createdAt): void
    {
        $receipt = ZimraSale::create(['transaction_id' => $sale->id, 'business_id' => 'biz-1', 'device_id' => '321', 'status' => 'fiscalised']);
        DB::table('zimra_sales')->where('id', $receipt->id)->update(['created_at' => $createdAt]);
    }

    private function sentCount(): int
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
    }

    private function lastBody(): string
    {
        return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage()->getTextBody();
    }

    public function test_a_failed_sale_is_emailed_with_zimras_code(): void
    {
        $this->makeSale(['fiscal_status' => 'failed', 'fiscal_error' => 'RCPT020', 'sale_number' => 'S-1']);

        app(ZimraHealthMonitor::class)->check();

        $this->assertSame(1, $this->sentCount());
        $this->assertStringContainsString('S-1', $this->lastBody());
        $this->assertStringContainsString('RCPT020', $this->lastBody());
    }

    public function test_a_sale_inside_the_confirmation_window_is_not_a_problem_yet(): void
    {
        $this->makeSale(['fiscal_status' => 'pending'], '-10 minutes');

        app(ZimraHealthMonitor::class)->check();

        $this->assertSame(0, $this->sentCount());
    }

    public function test_a_sale_stuck_past_the_window_is_a_problem(): void
    {
        $this->makeSale(['fiscal_status' => 'pending'], '-25 minutes');

        app(ZimraHealthMonitor::class)->check();

        $this->assertSame(1, $this->sentCount());
    }

    public function test_the_same_problem_is_not_emailed_every_run_but_a_new_one_is(): void
    {
        $this->makeSale(['fiscal_status' => 'failed']);
        $monitor = app(ZimraHealthMonitor::class);

        $monitor->check();
        $monitor->check();
        $this->assertSame(1, $this->sentCount());

        $this->makeSale(['fiscal_status' => 'failed']);
        $monitor->check();
        $this->assertSame(2, $this->sentCount());
    }

    public function test_an_unresolved_problem_gets_a_daily_reminder(): void
    {
        $this->makeSale(['fiscal_status' => 'failed']);
        $monitor = app(ZimraHealthMonitor::class);
        $monitor->check();

        $this->travel(25)->hours();
        $monitor->check();

        $this->assertSame(2, $this->sentCount());
    }

    public function test_a_recovery_is_emailed_once(): void
    {
        $sale = $this->makeSale(['fiscal_status' => 'failed']);
        $monitor = app(ZimraHealthMonitor::class);
        $monitor->check();

        $sale->update(['fiscal_status' => 'fiscalised']);
        $monitor->check();
        $monitor->check();

        $this->assertSame(2, $this->sentCount());
        $this->assertStringContainsString('accounted for', $this->lastBody());
    }

    public function test_a_sale_that_never_reached_the_queue_is_a_problem_once_receipts_have_started(): void
    {
        $this->makeReceipt($this->makeSale(['fiscal_status' => 'fiscalised'], '-3 hours'), now()->subHours(3));
        $this->makeSale([], '-1 hour');

        app(ZimraHealthMonitor::class)->check();

        $this->assertStringContainsString('never reached', $this->lastBody());
    }

    public function test_sales_from_before_the_first_receipt_are_not_problems(): void
    {
        $this->makeSale([], '-2 days');
        $this->makeReceipt($this->makeSale(['fiscal_status' => 'fiscalised'], '-1 hour'), now()->subHour());

        app(ZimraHealthMonitor::class)->check();

        $this->assertSame(0, $this->sentCount());
    }

    public function test_excluded_sales_and_disabled_businesses_are_ignored(): void
    {
        $this->makeSale(['fiscal_status' => 'excluded', 'fiscalisation_requested' => false]);
        Business::create(['id' => 'biz-2', 'name' => 'Off', 'fiscalisation_enabled' => false]);
        $this->makeSale(['business_id' => 'biz-2', 'fiscal_status' => 'failed']);

        app(ZimraHealthMonitor::class)->check();

        $this->assertSame(0, $this->sentCount());
    }

    public function test_the_command_runs_without_any_recipients_set(): void
    {
        config(['zimra.failure_alert_emails' => []]);
        Mail::fake();
        $this->makeSale(['fiscal_status' => 'failed']);

        $this->artisan('zimra:check-health')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_failed_invoice_is_emailed_too(): void
    {
        Invoice::create([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'customer_id' => (string) Str::uuid(),
            'invoice_number' => 'INV-9',
            'status' => 'sent',
            'issue_date' => now()->toDateString(),
            'total' => 115,
            'created_by_user_id' => (string) Str::uuid(),
            'fiscal_status' => 'failed',
            'fiscal_error' => 'RCPT020',
        ]);

        app(ZimraHealthMonitor::class)->check();

        $this->assertStringContainsString('INV-9', $this->lastBody());
        $this->assertStringContainsString('RCPT020', $this->lastBody());
    }

    public function test_the_email_says_when_a_day_must_be_closed_in_the_portal(): void
    {
        $this->makeSale(['fiscal_status' => 'failed']);
        ZimraFiscalDay::create([
            'business_id' => 'biz-1',
            'device_id' => '321',
            'fiscal_day_no' => 7,
            'closing_error_code' => ZimraFiscalDay::MANUAL_CLOSE_REQUIRED,
            'error_message' => 'Fiscal day 7 must be closed in the ZIMRA Public Portal.',
        ]);

        app(ZimraHealthMonitor::class)->check();

        $this->assertStringContainsString('Device 321: Fiscal day 7 must be closed in the ZIMRA Public Portal.', $this->lastBody());
    }
}
