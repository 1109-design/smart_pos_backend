<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeTransactionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The BackOffice auth middleware calls tenancy()->initialize(), which
     * re-points the central DB connection in a way that clashes with
     * RefreshDatabase's in-memory test database. The controller under test is
     * single-DB and only reads the backoffice session, so bypass the
     * middleware and set the session directly.
     */
    private function actingBackOfficeSession(string $tenantId): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $tenantId, 'pairing_code' => '123456']);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'email' => 'office-owner@example.com',
            'is_active' => true,
        ]);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    public function test_transactions_page_lists_fiscal_status(): void
    {
        $tenantId = 'tenant-office-tx-1';
        $user = $this->actingBackOfficeSession($tenantId);

        Transaction::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'user_id' => $user->id,
            'subtotal' => 10,
            'total' => 11.55,
            'tax_total' => 1.55,
            'base_currency' => 'USD',
            'status' => 'completed',
            'sale_number' => '202607-TEST-1',
            'fiscal_status' => 'fiscalised',
            'fiscal_receipt_number' => '12345',
            'fiscal_qr_code' => 'https://fdmstest.zimra.co.zw/0000036961190720260000000123abcdef1234567890',
        ]);

        $response = $this->get('/office/transactions');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/Transactions')
            ->has('transactions.data', 1)
            ->where('transactions.data.0.fiscal_status', 'fiscalised')
            ->where('transactions.data.0.fiscal_receipt_number', '12345')
            ->has('transactions.data.0.fiscal_qr_data_uri')
            ->where('fiscal_summary.fiscalised', 1)
        );
    }

    public function test_fiscal_filter_narrows_results(): void
    {
        $tenantId = 'tenant-office-tx-2';
        $user = $this->actingBackOfficeSession($tenantId);

        foreach ([['fiscalised', '1'], ['pending', '2'], [null, '3']] as [$fiscalStatus, $suffix]) {
            Transaction::create([
                'id' => (string) Str::uuid(),
                'business_id' => $tenantId,
                'user_id' => $user->id,
                'subtotal' => 10,
                'total' => 10,
                'tax_total' => 0,
                'base_currency' => 'USD',
                'status' => 'completed',
                'sale_number' => '202607-TEST-'.$suffix,
                'fiscal_status' => $fiscalStatus,
            ]);
        }

        $response = $this->get('/office/transactions?fiscal=pending');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('transactions.data', 1)
            ->where('transactions.data.0.fiscal_status', 'pending')
        );
    }
}
