<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\CostCentre;
use App\Models\Business;
use App\Models\Device;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cost centres (Phase 10): the master and defaults sync from devices,
 * owner / manager only, and a split must add up to 100%.
 */
class CostCentreSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-cc';

    private function deviceFor(string $role): string
    {
        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole($role);
        $token = $user->createToken('t')->plainTextToken;
        Device::create([
            'tenant_id' => self::BIZ, 'name' => 'Till', 'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $token)[0], 'is_revoked' => false,
        ]);

        return $token;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $token, string $table, string $uuid, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table, 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    public function test_cost_centres_splits_and_defaults_sync(): void
    {
        $owner = $this->deviceFor('business_owner');
        $shop = (string) Str::uuid();
        $warehouse = (string) Str::uuid();
        foreach ([[$shop, 'SHP'], [$warehouse, 'WHS']] as [$id, $code]) {
            $this->push($owner, 'cost_centres', $id, ['business_id' => self::BIZ, 'code' => $code, 'name' => $code])
                ->assertJsonPath('errors', []);
        }
        $split = (string) Str::uuid();
        $this->push($owner, 'cost_centres', $split, [
            'business_id' => self::BIZ, 'code' => 'SPL', 'name' => 'Split', 'is_split' => true,
            'split' => [['cost_centre_id' => $shop, 'pct' => 60], ['cost_centre_id' => $warehouse, 'pct' => 40]],
        ])->assertJsonPath('errors', []);
        $this->assertCount(2, CostCentre::findOrFail($split)->split);

        $bad = (string) Str::uuid();
        $this->push($owner, 'cost_centres', $bad, [
            'business_id' => self::BIZ, 'code' => 'BAD', 'name' => 'Bad', 'is_split' => true,
            'split' => [['cost_centre_id' => $shop, 'pct' => 50]],
        ])->assertJsonPath('errors.0.uuid', $bad);

        $location = Location::create(['id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'name' => 'Shop']);
        $this->push($owner, 'cost_centre_defaults', 'location:'.$location->id, [
            'kind' => 'location', 'id' => $location->id, 'cost_centre_id' => $shop,
        ])->assertJsonPath('errors', []);
        $this->assertSame($shop, $location->fresh()->cost_centre_id);

        $this->push($owner, 'accounting_settings', self::BIZ, [
            'business_id' => self::BIZ, 'cost_centre_required' => true,
            'updated_at' => now()->addSecond()->toIso8601String(),
        ])->assertJsonPath('errors', []);
        $this->assertTrue((bool) Business::findOrFail(self::BIZ)->cost_centre_required);
    }

    public function test_a_cashier_cannot_change_cost_centres(): void
    {
        $cashier = $this->deviceFor('cashier');
        $id = (string) Str::uuid();
        $this->push($cashier, 'cost_centres', $id, ['business_id' => self::BIZ, 'code' => 'X', 'name' => 'X'])
            ->assertJsonPath('errors.0.uuid', $id);
        $this->assertNull(CostCentre::find($id));
    }
}
