<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\ZReport;
use App\Models\Business;
use App\Models\Device;
use App\Models\Location;
use App\Models\Shift;
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
 * Cash-ups (Phase 7): a shift's Z-report keeps its figures for good, the
 * expected cash counts drops and paid-outs, and the variance threshold
 * syncs with the accounting settings.
 */
class CashUpSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-cash-up';

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
        ]]])->assertOk()->assertJsonPath('errors', []);
    }

    /**
     * @return array<string, mixed>
     */
    private function zReport(string $shiftId, array $extra = []): array
    {
        return [
            'business_id' => self::BIZ, 'shift_id' => $shiftId, 'cashier_id' => (string) Str::uuid(),
            'report_number' => 'Z-001-202610-0001', 'opened_at' => '2026-10-15T08:00:00Z',
            'closed_at' => '2026-10-15T17:00:00Z', 'data' => ['gross_sales' => 500, 'variance' => -5],
            'variance' => -5, 'status' => 'pending_approval', ...$extra,
        ];
    }

    public function test_a_z_report_keeps_its_figures_and_records_its_approval(): void
    {
        $id = (string) Str::uuid();
        $shiftId = (string) Str::uuid();
        $this->push('z_reports', $id, $this->zReport($shiftId));

        $approver = (string) Str::uuid();
        $this->push('z_reports', $id, $this->zReport($shiftId, [
            'data' => ['gross_sales' => 1, 'variance' => 0], 'variance' => 0,
            'status' => 'approved', 'approved_by_user_id' => $approver, 'printed_at' => '2026-10-15T17:05:00Z',
        ]));
        $this->push('z_reports', $id, $this->zReport($shiftId, ['status' => 'pending_approval', 'printed_at' => null]));

        $z = ZReport::findOrFail($id);
        $this->assertSame(500, $z->data['gross_sales']);
        $this->assertSame(-5.0, $z->variance);
        $this->assertSame('approved', $z->status);
        $this->assertSame($approver, $z->approved_by_user_id);
        $this->assertNotNull($z->printed_at);
    }

    public function test_expected_cash_counts_cash_moved_in_and_out(): void
    {
        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'name' => 'Shop']);
        $shiftId = (string) Str::uuid();
        $this->push('till_cash_movements', (string) Str::uuid(), [
            'business_id' => self::BIZ, 'location_id' => $location->id, 'shift_id' => $shiftId,
            'till_id' => (string) Str::uuid(), 'type' => 'cash_out', 'amount' => 40, 'recorded_by_user_id' => $this->user->id,
        ]);
        $this->push('shifts', $shiftId, [
            'business_id' => self::BIZ, 'location_id' => $location->id, 'cashier_id' => $this->user->id,
            'opened_at' => now()->subHours(8)->toIso8601String(), 'closed_at' => now()->toIso8601String(),
            'status' => 'closed', 'opening_float' => 100, 'counted_cash' => 60,
        ]);

        $shift = Shift::findOrFail($shiftId);
        $this->assertEquals(60, (float) $shift->expected_cash);
        $this->assertEquals(0, (float) $shift->variance);
    }

    public function test_the_threshold_syncs_and_the_new_accounts_exist(): void
    {
        $this->push('accounting_settings', self::BIZ, [
            'business_id' => self::BIZ, 'cash_up_variance_threshold' => 2.5,
            'updated_at' => now()->addSecond()->toIso8601String(),
        ]);
        $this->assertEquals(2.5, (float) Business::findOrFail(self::BIZ)->cash_up_variance_threshold);

        $roles = app(AccountRoleMappingService::class);
        $this->assertSame('6067', $roles->resolve(self::BIZ, 'cash_over_short')->code);
        $this->assertSame('2060', $roles->resolve(self::BIZ, 'layby_deposits')->code);
        $this->assertSame('sales', AccountingPeriod::moduleFor('layby_payment'));
    }
}
