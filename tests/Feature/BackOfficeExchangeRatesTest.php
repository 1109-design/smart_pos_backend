<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\ApprovalRequest;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FX·06 — the BackOffice audit-history view over exchange_rates. Rows are
 * never written by this controller (read-only, see ExchangeRatesController);
 * it only has to surface history that ApprovalService already wrote
 * correctly (see BackOfficeApprovalsTest's rate-change coverage for that
 * side).
 */
class BackOfficeExchangeRatesTest extends TestCase
{
    use RefreshDatabase;

    private function actingBackOfficeSession(string $tenantId, string $role = 'business_owner'): User
    {
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com', 'pairing_code' => substr(md5($tenantId), 0, 6)]);

        $user = User::factory()->create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'email' => $tenantId.'-user@example.com',
            'is_active' => true,
        ]);

        session([
            'backoffice' => [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'role' => $role,
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    private function seedCurrencies(): void
    {
        Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'is_base' => true, 'is_enabled' => true]);
        Currency::firstOrCreate(['code' => 'ZWG'], ['name' => 'Zimbabwe Gold', 'symbol' => 'ZWG', 'is_base' => false, 'is_enabled' => true]);
    }

    public function test_a_cashier_cannot_view_exchange_rate_history(): void
    {
        $tenantId = 'tenant-fx-perm-1';
        $this->actingBackOfficeSession($tenantId, 'cashier');
        $this->seedCurrencies();

        $this->get('/office/exchange-rates')->assertForbidden();
    }

    public function test_a_manager_can_view_the_currency_list_with_current_rates(): void
    {
        $tenantId = 'tenant-fx-index-1';
        $manager = $this->actingBackOfficeSession($tenantId, 'manager');
        $this->seedCurrencies();

        $olderId = (string) Str::uuid();
        ExchangeRate::create([
            'id' => $olderId,
            'business_id' => $tenantId,
            'from_currency' => 'ZWG',
            'to_currency' => 'USD',
            'rate' => 26.5,
            'set_by_user_id' => $manager->id,
            'valid_from' => now()->subDay(),
            'valid_until' => now(),
        ]);
        $currentId = (string) Str::uuid();
        ExchangeRate::create([
            'id' => $currentId,
            'business_id' => $tenantId,
            'from_currency' => 'ZWG',
            'to_currency' => 'USD',
            'rate' => 27.25,
            'set_by_user_id' => $manager->id,
            'valid_from' => now(),
            'valid_until' => null,
        ]);

        $response = $this->get('/office/exchange-rates')->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/ExchangeRates')
            ->where('base_currency', 'USD')
            ->where('currencies.0.code', 'ZWG')
            // A whole-number float (27.0) round-trips through JSON as an
            // int (PHP's json_encode drops the trailing .0), which would
            // fail this assertion on type alone even though the page
            // renders identically either way — use a fractional rate so
            // the assertion isn't testing a JSON-encoding quirk.
            ->where('currencies.0.current_rate', 27.25)
            ->where('currencies.0.change_count', 2)
        );
    }

    public function test_history_shows_every_row_with_requester_approver_and_reason(): void
    {
        $tenantId = 'tenant-fx-history-1';
        $manager = $this->actingBackOfficeSession($tenantId, 'manager');
        $this->seedCurrencies();
        $requester = User::factory()->create(['business_id' => $tenantId, 'name' => 'Requesting Cashier']);

        $rateId = (string) Str::uuid();
        ExchangeRate::create([
            'id' => $rateId,
            'business_id' => $tenantId,
            'from_currency' => 'ZWG',
            'to_currency' => 'USD',
            'rate' => 27.0,
            'set_by_user_id' => $manager->id,
            'valid_from' => now(),
            'valid_until' => null,
        ]);
        // Same id as the rate's subject_id, own fresh id — the real join
        // (see ExchangeRate::approvalRequest()).
        ApprovalRequest::create([
            'id' => (string) Str::uuid(),
            'business_id' => $tenantId,
            'subject_type' => 'ExchangeRate',
            'subject_id' => $rateId,
            'action' => 'change_exchange_rate',
            'requested_by_user_id' => $requester->id,
            'status' => 'approved',
            'approver_user_id' => $manager->id,
            'approved_at' => now(),
            'reason' => 'Parallel market moved',
        ]);

        $response = $this->get('/office/exchange-rates/ZWG')->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('BackOffice/ExchangeRateHistory')
            ->where('currency.code', 'ZWG')
            ->where('history.0.id', $rateId)
            ->where('history.0.is_current', true)
            ->where('history.0.requested_by', 'Requesting Cashier')
            ->where('history.0.approved_by', $manager->name)
            ->where('history.0.reason', 'Parallel market moved')
        );
    }
}
