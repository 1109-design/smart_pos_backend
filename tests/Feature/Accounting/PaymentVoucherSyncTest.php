<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\PaymentVoucher;
use App\Models\Business;
use App\Models\Device;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierPayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Payables (Phase 8): payment vouchers keep separation of duties and only
 * move forward once approved; prepayments and revised invoices sync.
 */
class PaymentVoucherSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-vouchers';

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user = User::factory()->create(['business_id' => self::BIZ]);
        $this->user->assignRole('business_owner');
        $this->token = $this->user->createToken('t')->plainTextToken;
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
        ]]])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function voucher(array $extra = []): array
    {
        return [
            'business_id' => self::BIZ, 'voucher_number' => 'PV-001-202610-0001', 'voucher_type' => 'expense',
            'payee' => 'City Council', 'voucher_date' => '2026-10-15', 'description' => 'Rates',
            'total' => 100, 'paid_amount' => 0, 'status' => 'pending_approval',
            'lines' => [['kind' => 'expense', 'amount' => 100]], 'payments' => [],
            'history' => [['action' => 'submitted']], 'created_by_user_id' => 'clerk', ...$extra,
        ];
    }

    public function test_nobody_approves_their_own_voucher(): void
    {
        $id = (string) Str::uuid();
        $this->push('payment_vouchers', $id, $this->voucher(['status' => 'approved', 'approved_by_user_id' => 'clerk']))
            ->assertJsonPath('errors.0.uuid', $id);
        $this->assertNull(PaymentVoucher::find($id));
    }

    public function test_an_approved_voucher_only_moves_forward(): void
    {
        $id = (string) Str::uuid();
        $approved = ['status' => 'approved', 'approved_by_user_id' => 'boss', 'history' => [['action' => 'submitted'], ['action' => 'approved']]];
        $this->push('payment_vouchers', $id, $this->voucher($approved))->assertJsonPath('errors', []);
        $this->push('payment_vouchers', $id, $this->voucher([
            ...$approved, 'status' => 'part_paid', 'paid_amount' => 40, 'payments' => [['amount' => 40]],
            'history' => [['action' => 'submitted'], ['action' => 'approved'], ['action' => 'paid']],
        ]))->assertJsonPath('errors', []);

        // A stale copy from another device: back to approved, nothing paid,
        // and a bigger total.
        $this->push('payment_vouchers', $id, $this->voucher([...$approved, 'total' => 900]))->assertJsonPath('errors', []);

        $v = PaymentVoucher::findOrFail($id);
        $this->assertSame('part_paid', $v->status);
        $this->assertSame(40.0, $v->paid_amount);
        $this->assertSame(100.0, $v->total);
        $this->assertCount(3, $v->history);
        $this->assertSame('boss', $v->approved_by_user_id);
    }

    public function test_prepayments_and_revised_invoices_sync(): void
    {
        $supplier = Supplier::create(['id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'name' => 'Acme']);
        $paymentId = (string) Str::uuid();
        $this->push('supplier_payments', $paymentId, [
            'business_id' => self::BIZ, 'supplier_id' => $supplier->id, 'amount' => 50,
            'payment_date' => '2026-10-15', 'method' => 'bank_transfer', 'is_prepayment' => true,
        ])->assertJsonPath('errors', []);
        $this->assertTrue(SupplierPayment::findOrFail($paymentId)->is_prepayment);

        $oldId = (string) Str::uuid();
        $newId = (string) Str::uuid();
        foreach ([[$oldId, 'superseded', null], [$newId, 'draft', $oldId]] as [$id, $status, $replaces]) {
            $this->push('supplier_invoices', $id, [
                'business_id' => self::BIZ, 'supplier_id' => $supplier->id, 'invoice_number' => 'INV-9',
                'invoice_date' => '2026-10-15', 'total' => 80, 'status' => $status, 'replaces_invoice_id' => $replaces,
            ])->assertJsonPath('errors', []);
        }
        $this->assertSame($oldId, SupplierInvoice::findOrFail($newId)->replaces_invoice_id);

        $this->assertSame('2070', app(AccountRoleMappingService::class)->resolve(self::BIZ, 'vouchers_payable')->code);
        $this->assertSame('ap', AccountingPeriod::moduleFor('payment_voucher'));
    }
}
