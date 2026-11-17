<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ZIMRA's answer lives only on the server, so tills always get the server's
 * fiscal result: in what they pull, and from /sync/fiscal-results when they
 * still see a document as unconfirmed.
 */
class SyncFiscalResultsTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-fiscal-results';

    /** @return array{0: Device, 1: string} */
    private function till(?string $tenant = null): array
    {
        $tenant ??= $this->tenantId;
        if (! Tenant::find($tenant)) {
            Tenant::create(['id' => $tenant, 'business_name' => $tenant, 'owner_email' => $tenant.'@example.com']);
        }
        $user = User::factory()->create(['business_id' => $tenant, 'email' => Str::random(8).'@example.com']);
        $plain = $user->createToken('till')->plainTextToken;
        $device = Device::create([
            'tenant_id' => $tenant,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return [$device, $plain];
    }

    private function fiscalisedSale(?string $tenant = null): Transaction
    {
        $sale = Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenant ?? $this->tenantId,
            'user_id' => (string) Str::uuid(),
            'subtotal' => 70,
            'tax_total' => 9.13,
            'total' => 70,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '20261116-252B-102',
            'fiscalisation_requested' => true,
        ]);
        $sale->forceFill([
            'fiscal_status' => 'fiscalised',
            'fiscal_receipt_number' => '485013127',
            'fiscal_qr_code' => 'https://fdms.zimra.co.zw/qr',
        ])->saveQuietly();

        return $sale;
    }

    public function test_a_stale_push_relayed_to_other_tills_carries_the_servers_fiscal_result(): void
    {
        [$pusher] = $this->till();
        [, $token] = $this->till();
        $sale = $this->fiscalisedSale();

        // The other till's copy, sent before it heard ZIMRA's answer.
        SyncRecord::create([
            'business_id' => $this->tenantId,
            'table_name' => 'transactions',
            'record_uuid' => $sale->id,
            'operation' => 'upsert',
            'payload' => ['sale_number' => '20261116-252B-102', 'status' => 'completed', 'total' => 70],
            'source_updated_at' => now(),
            'synced_at' => now(),
            'device_id' => $pusher->id,
        ]);

        $records = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?tables[]=transactions')
            ->assertOk()
            ->json('records');

        $this->assertCount(1, $records);
        $this->assertSame('fiscalised', $records[0]['payload']['fiscal_status']);
        $this->assertSame('485013127', $records[0]['payload']['fiscal_receipt_number']);
        $this->assertSame('https://fdms.zimra.co.zw/qr', $records[0]['payload']['fiscal_qr_code']);
        $this->assertSame('20261116-252B-102', $records[0]['payload']['sale_number']);
    }

    public function test_a_delete_or_a_record_the_server_doesnt_have_is_left_alone(): void
    {
        [, $token] = $this->till();
        $missing = (string) Str::uuid();
        SyncRecord::create([
            'business_id' => $this->tenantId,
            'table_name' => 'transactions',
            'record_uuid' => $missing,
            'operation' => 'upsert',
            'payload' => ['status' => 'completed'],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        $records = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/sync/pull?tables[]=transactions')
            ->assertOk()
            ->json('records');

        $this->assertSame(['status' => 'completed'], $records[0]['payload']);
    }

    public function test_fiscal_results_answers_for_the_tills_own_business_only(): void
    {
        [, $token] = $this->till();
        $ours = $this->fiscalisedSale();
        $theirs = $this->fiscalisedSale('tenant-someone-else');

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/fiscal-results', [
                'transactions' => [$ours->id, $theirs->id, (string) Str::uuid()],
                'invoices' => [(string) Str::uuid()],
            ])
            ->assertOk();

        $this->assertSame([[
            'id' => $ours->id,
            'fiscal_status' => 'fiscalised',
            'fiscal_error' => null,
            'fiscal_receipt_number' => '485013127',
            'fiscal_qr_code' => 'https://fdms.zimra.co.zw/qr',
        ]], $response->json('transactions'));
        $this->assertSame([], $response->json('invoices'));
        $this->assertSame([], $response->json('credit_notes'));
    }

    public function test_fiscal_results_needs_a_paired_till(): void
    {
        $this->postJson('/api/v1/sync/fiscal-results', ['transactions' => []])->assertUnauthorized();
    }
}
