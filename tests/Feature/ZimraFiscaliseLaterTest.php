<?php

namespace Tests\Feature;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Payment;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\User;
use App\Models\Zimra\ZimraDevice;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\SalePostingService;
use App\Services\Zimra\ZimraSalesService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZimraFiscaliseLaterTest extends TestCase
{
    use RefreshDatabase;

    private int $lastGlobalNo = 46;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'zimra.fiscalisation.enabled' => true,
            'zimra.fiscalisation.allowed_environments' => ['testing'],
            'zimra.fiscalisation.allowed_hosts' => [],
        ]);

        Business::create([
            'id' => 'biz-1',
            'name' => 'Fiscal Shop',
            'currency_code' => 'USD',
            'fiscalisation_enabled' => true,
            'tin' => '1234567890',
        ]);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privateKeyPem);

        ZimraDevice::create([
            'business_id' => 'biz-1',
            'tin' => '1234567890',
            'device_id' => '321',
            'is_active' => true,
            'status' => 'active',
            'private_key_data' => $privateKeyPem,
            'fiscal_day_opened_at' => Carbon::now()->subHour(),
            'applicable_taxes' => [['taxID' => 3, 'taxPercent' => 15.0, 'taxName' => 'VAT 15%']],
            'tax_codes' => [3 => 'A'],
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'GetStatus')) {
                return Http::response([
                    'fiscalDayStatus' => 'FiscalDayOpened',
                    'lastFiscalDayNo' => 7,
                    'lastReceiptGlobalNo' => $this->lastGlobalNo,
                ]);
            }

            $this->lastGlobalNo++;

            return Http::response([
                'receiptID' => 11939000 + $this->lastGlobalNo,
                'serverDate' => now()->toIso8601String(),
                'receiptServerSignature' => ['signature' => 'server-signature'],
                'validationErrors' => [],
            ]);
        });
    }

    /**
     * A sale the cashier kept off ZIMRA at checkout, as it stands once the
     * till has restored its VAT and pushed it (tax 15 inside a 115 total).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function makeExcludedSale(array $attributes = [], float $tax = 15.0): Transaction
    {
        $transaction = Transaction::create(array_merge([
            'id' => (string) Str::uuid(),
            'business_id' => 'biz-1',
            'user_id' => (string) Str::uuid(),
            'subtotal' => 115 - $tax,
            'tax_total' => $tax,
            'total' => 115,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '20261115-0001-1',
            'fiscalisation_requested' => false,
            'fiscal_status' => 'excluded',
        ], $attributes));
        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $transaction->id,
            'product_id' => (string) Str::uuid(),
            'product_name' => 'Widget',
            'quantity' => 2,
            'unit_price' => 57.50,
            'tax_amount' => $tax,
            'line_total' => 115.00,
        ]);
        Payment::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $transaction->id,
            'method' => 'cash',
            'amount' => 115.00,
            'currency_code' => 'USD',
            'base_equivalent' => 115.00,
        ]);

        return $transaction;
    }

    private function service(): ZimraSalesService
    {
        return app(ZimraSalesService::class);
    }

    private function submissions(): int
    {
        return count(Http::recorded(fn (Request $request) => str_contains($request->url(), 'SubmitReceipt')));
    }

    private function vatRestoreJournals(Transaction $transaction): int
    {
        return JournalHeader::where('source_type', 'sale_vat_restore')
            ->where('source_id', $transaction->id)
            ->count();
    }

    public function test_an_excluded_sale_is_fiscalised_later_and_the_tills_are_told(): void
    {
        $transaction = $this->makeExcludedSale();

        $result = $this->service()->fiscaliseLater($transaction);

        $this->assertTrue($result['fiscalised']);
        $fresh = $transaction->fresh();
        $this->assertSame('fiscalised', $fresh->fiscal_status);
        $this->assertTrue($fresh->fiscalisation_requested);
        $this->assertNotNull($fresh->fiscal_receipt_number);
        $this->assertSame(1, $this->submissions());

        $payload = SyncRecord::where('table_name', 'transactions')
            ->where('record_uuid', $transaction->id)
            ->latest('id')
            ->first()
            ->payload;
        $this->assertSame('fiscalised', $payload['fiscal_status']);
        $this->assertTrue($payload['fiscalisation_requested']);
        $this->assertEqualsWithDelta(15.0, $payload['tax_total'], 0.001);
    }

    public function test_a_sale_that_is_already_fiscalised_is_not_sent_again(): void
    {
        $transaction = $this->makeExcludedSale(['fiscalisation_requested' => true, 'fiscal_status' => 'fiscalised']);

        $result = $this->service()->fiscaliseLater($transaction);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['refused']);
        $this->assertSame(0, $this->submissions());
    }

    public function test_a_voided_sale_cannot_be_fiscalised_later(): void
    {
        $transaction = $this->makeExcludedSale(['status' => 'voided']);

        $result = $this->service()->fiscaliseLater($transaction);

        $this->assertTrue($result['refused']);
        $this->assertSame('excluded', $transaction->fresh()->fiscal_status);
        $this->assertSame(0, $this->submissions());
    }

    public function test_a_return_cannot_be_fiscalised_later_on_its_own(): void
    {
        $transaction = $this->makeExcludedSale(['status' => 'refunded', 'total' => -115, 'subtotal' => -100, 'tax_total' => -15]);

        $result = $this->service()->fiscaliseLater($transaction);

        $this->assertTrue($result['refused']);
        $this->assertSame(0, $this->submissions());
    }

    public function test_nothing_is_sent_when_the_business_has_fiscalisation_off(): void
    {
        Business::where('id', 'biz-1')->update(['fiscalisation_enabled' => false]);
        $transaction = $this->makeExcludedSale();

        $result = $this->service()->fiscaliseLater($transaction);

        $this->assertTrue($result['refused']);
        $this->assertFalse($transaction->fresh()->fiscalisation_requested);
        $this->assertSame(0, $this->submissions());
    }

    public function test_the_vat_moves_from_revenue_to_vat_output_when_the_server_owns_the_books(): void
    {
        Business::where('id', 'biz-1')->update(['accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness('biz-1');

        // Posted at checkout with no VAT, as the cashier kept it off ZIMRA.
        $transaction = $this->makeExcludedSale(tax: 0.0);
        app(SalePostingService::class)->postIfReady($transaction->fresh());
        $this->assertSame(1, JournalHeader::where('source_type', 'sale')->where('source_id', $transaction->id)->count());

        // The till restores the VAT, then asks for the sale to be fiscalised.
        $transaction->update(['subtotal' => 100, 'tax_total' => 15]);
        TransactionItem::where('transaction_id', $transaction->id)->update(['tax_amount' => 15]);

        $this->service()->fiscaliseLater($transaction->fresh());
        $this->service()->fiscaliseLater($transaction->fresh());

        $this->assertSame(1, $this->vatRestoreJournals($transaction));
        $journal = JournalHeader::where('source_type', 'sale_vat_restore')->where('source_id', $transaction->id)->first();
        $this->assertSame('posted', $journal->status);

        $revenue = GlAccount::where('business_id', 'biz-1')->where('code', '4000')->value('id');
        $vat = GlAccount::where('business_id', 'biz-1')->where('code', '2030')->value('id');
        $this->assertEqualsWithDelta(15.0, (float) $journal->lines()->where('gl_account_id', $revenue)->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(15.0, (float) $journal->lines()->where('gl_account_id', $vat)->sum('credit'), 0.001);
    }

    public function test_a_business_on_till_posting_gets_no_server_vat_journal(): void
    {
        Business::where('id', 'biz-1')->update([
            'accounting_go_live_date' => '2026-01-01',
            'client_gl_posting_enabled_at' => '2026-01-01',
        ]);
        (new ChartOfAccountsSeeder)->seedForBusiness('biz-1');
        $transaction = $this->makeExcludedSale();

        $this->service()->fiscaliseLater($transaction);

        $this->assertSame(0, $this->vatRestoreJournals($transaction));
    }

    private function deviceToken(string $tenantId): string
    {
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        $user = User::factory()->create();
        $plain = $user->createToken('sync-test')->plainTextToken;

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Till 1',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_the_endpoint_fiscalises_a_sale_of_the_devices_business(): void
    {
        $token = $this->deviceToken('biz-1');
        $transaction = $this->makeExcludedSale();

        $this->withToken($token)
            ->postJson("/api/v1/sync/fiscalise-later/{$transaction->id}")
            ->assertOk()
            ->assertJson([
                'success' => true,
                'fiscalised' => true,
                'fiscal_status' => 'fiscalised',
                'fiscalisation_requested' => true,
            ]);
    }

    public function test_the_endpoint_refuses_a_voided_sale_with_its_reason(): void
    {
        $token = $this->deviceToken('biz-1');
        $transaction = $this->makeExcludedSale(['status' => 'voided']);

        $this->withToken($token)
            ->postJson("/api/v1/sync/fiscalise-later/{$transaction->id}")
            ->assertStatus(422)
            ->assertJson(['refused' => true, 'message' => 'Only completed and credit sales can be fiscalised']);
    }

    public function test_the_endpoint_never_reaches_another_business_sale(): void
    {
        $token = $this->deviceToken('other-biz');
        $transaction = $this->makeExcludedSale();

        $this->withToken($token)
            ->postJson("/api/v1/sync/fiscalise-later/{$transaction->id}")
            ->assertNotFound();
        $this->assertSame('excluded', $transaction->fresh()->fiscal_status);
    }
}
