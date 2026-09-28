<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Zimra\ZimraSalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Product exchanges (see the Flutter ExchangeService) are born 'completed',
 * so the void/refund status gate never sees them — these cover their own
 * approval gate, the immutability of their link to the original sale, and
 * that they're kept out of ZIMRA fiscalisation.
 */
class SyncProductExchangeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-sync-exchange';

    private string $userId;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => $this->tenantId.'@example.com']);
        $user = User::factory()->create(['email' => $this->tenantId.'-owner@example.com']);
        $this->token = $user->createToken('sync-test')->plainTextToken;

        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $this->token)[0],
            'is_revoked' => false,
        ]);

        $this->userId = (string) Str::uuid();
    }

    private function makeOriginalSale(): string
    {
        $id = (string) Str::uuid();
        Transaction::create([
            'id' => $id,
            'business_id' => $this->tenantId,
            'user_id' => $this->userId,
            'subtotal' => 40,
            'tax_total' => 0,
            'discount_total' => 0,
            'total' => 40,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202609-1',
        ]);

        return $id;
    }

    private function approveExchangeOf(string $originalId): ApprovalRequest
    {
        return ApprovalRequest::create([
            'business_id' => $this->tenantId,
            'subject_type' => 'Transaction',
            'subject_id' => $originalId,
            'action' => 'exchange_transaction',
            'requested_by_user_id' => $this->userId,
            'status' => 'approved',
            'approver_user_id' => (string) Str::uuid(),
            'approved_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pushTransaction(string $uuid, array $overrides = []): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'transactions',
                    'uuid' => $uuid,
                    'operation' => 'upsert',
                    'payload' => array_merge([
                        'business_id' => $this->tenantId,
                        'user_id' => $this->userId,
                        'subtotal' => 20,
                        'tax_total' => 0,
                        'discount_total' => 0,
                        'total' => 20,
                        'base_currency' => 'USD',
                        'status' => 'completed',
                        'sale_number' => 'EXC-202609-1',
                    ], $overrides),
                    'updated_at' => now()->toIso8601String(),
                ]],
            ]);
    }

    public function test_an_exchange_without_an_approved_request_is_rejected(): void
    {
        $originalId = $this->makeOriginalSale();
        $exchangeId = (string) Str::uuid();

        $response = $this->pushTransaction($exchangeId, ['exchange_of_transaction_id' => $originalId]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseMissing('transactions', ['id' => $exchangeId]);
    }

    public function test_an_approval_for_a_different_sale_does_not_authorise_the_exchange(): void
    {
        $originalId = $this->makeOriginalSale();
        $otherSaleApproval = $this->approveExchangeOf($this->makeOriginalSale());
        $exchangeId = (string) Str::uuid();

        $response = $this->pushTransaction($exchangeId, [
            'exchange_of_transaction_id' => $originalId,
            'approval_request_id' => $otherSaleApproval->id,
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('errors'));
        $this->assertDatabaseMissing('transactions', ['id' => $exchangeId]);
    }

    public function test_an_approved_exchange_is_stored_with_its_link_and_skips_fiscalisation(): void
    {
        $zimra = $this->spy(ZimraSalesService::class);
        $originalId = $this->makeOriginalSale();
        $approval = $this->approveExchangeOf($originalId);
        $exchangeId = (string) Str::uuid();

        $response = $this->pushTransaction($exchangeId, [
            'exchange_of_transaction_id' => $originalId,
            'approval_request_id' => $approval->id,
        ]);

        $response->assertOk();
        $this->assertEmpty($response->json('errors'));

        $exchange = Transaction::findOrFail($exchangeId);
        $this->assertSame($originalId, $exchange->exchange_of_transaction_id);
        $this->assertSame('completed', $exchange->status);

        // Control: an ordinary completed sale pushed the same way IS queued.
        $saleId = (string) Str::uuid();
        $this->pushTransaction($saleId, ['sale_number' => '202609-2'])->assertOk();

        $zimra->shouldHaveReceived('queueFiscalisation')
            ->withArgs(fn (Transaction $tx) => $tx->id === $saleId)
            ->once();
        $zimra->shouldNotHaveReceived('queueFiscalisation', [\Mockery::on(fn (Transaction $tx) => $tx->id === $exchangeId)]);
    }

    public function test_a_later_upsert_can_neither_attach_nor_detach_an_exchange_link(): void
    {
        $originalId = $this->makeOriginalSale();
        $approval = $this->approveExchangeOf($originalId);
        $exchangeId = (string) Str::uuid();

        $this->pushTransaction($exchangeId, [
            'exchange_of_transaction_id' => $originalId,
            'approval_request_id' => $approval->id,
        ])->assertOk();

        // A full-snapshot re-upsert without the key keeps the link.
        $this->pushTransaction($exchangeId, ['notes' => 'edited'])->assertOk();
        $this->assertSame($originalId, Transaction::findOrFail($exchangeId)->exchange_of_transaction_id);

        // An existing plain sale can't be turned into an exchange later.
        $saleId = (string) Str::uuid();
        $this->pushTransaction($saleId, ['sale_number' => '202609-3'])->assertOk();
        $this->pushTransaction($saleId, ['exchange_of_transaction_id' => $originalId])->assertOk();
        $this->assertNull(Transaction::findOrFail($saleId)->exchange_of_transaction_id);
    }
}
