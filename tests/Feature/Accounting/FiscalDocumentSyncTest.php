<?php

namespace Tests\Feature\Accounting;

use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Zimra\FiscalDocument;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fiscal documents from devices (Phase 11.2) and the VAT return accounts
 * (Phase 11.1).
 */
class FiscalDocumentSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-fiscal';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
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
    private function push(string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => 'fiscal_documents', 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function note(array $extra = []): array
    {
        return [
            'business_id' => self::BIZ, 'document_type' => 'CreditNote', 'source_type' => 'sales_return',
            'source_id' => (string) Str::uuid(), 'document_number' => 'RET-1',
            'original_transaction_id' => (string) Str::uuid(), 'currency_code' => 'USD', 'total' => 10,
            'reason' => 'Returned', 'lines' => [['description' => 'X', 'quantity' => 1, 'line_total' => 10, 'tax_amount' => 0]],
            'status' => 'fiscalised', 'fiscal_receipt_id' => 'forged', ...$extra,
        ];
    }

    public function test_a_note_is_stored_queued_and_its_fiscal_fields_stay_the_servers(): void
    {
        Queue::fake();
        $id = (string) Str::uuid();
        $this->push($id, $this->note())->assertJsonPath('errors', []);

        $doc = FiscalDocument::findOrFail($id);
        $this->assertNotSame('fiscalised', $doc->status);
        $this->assertNull($doc->fiscal_receipt_id);
    }

    public function test_a_note_needs_a_reason_and_an_original(): void
    {
        foreach ([['reason' => ''], ['original_transaction_id' => null]] as $bad) {
            $id = (string) Str::uuid();
            $this->push($id, $this->note($bad))->assertJsonPath('errors.0.uuid', $id);
            $this->assertNull(FiscalDocument::find($id));
        }
    }

    public function test_the_vat_return_accounts_exist(): void
    {
        $roles = app(AccountRoleMappingService::class);
        $this->assertSame('2035', $roles->resolve(self::BIZ, 'vat_payable')->code);
        $this->assertSame('1155', $roles->resolve(self::BIZ, 'vat_refund_due')->code);
    }
}
