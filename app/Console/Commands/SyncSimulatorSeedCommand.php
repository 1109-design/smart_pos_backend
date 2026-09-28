<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Dev-only tooling for the Flutter app's Sync Simulator (Settings >
 * Developer Tools > Sync Simulator, debug builds only) — seeds one
 * Tenant+Business+owner User, then three real Device rows with real
 * Sanctum tokens (TILL-001, TILL-002, MANAGER-001), so the simulator can
 * drive the app's actual SyncService against this backend instead of a
 * mock. Never registered on any HTTP route. Reuses the exact
 * Tenant/Business/ChartOfAccountsSeeder/Device/createToken bootstrap
 * pattern the sync idempotency Feature tests already use (see
 * SyncDuplicatePushIdempotencyTest::actingDeviceToken()), just run once
 * against a real dev database instead of RefreshDatabase's sqlite
 * :memory:.
 */
class SyncSimulatorSeedCommand extends Command
{
    protected $signature = 'sync-simulator:seed {--business= : Business/tenant id to create (random uuid if omitted)}';

    protected $description = 'Seed a business and three device tokens (TILL-001, TILL-002, MANAGER-001) for the Flutter Sync Simulator dev tool';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to seed simulator test data into a production environment.');

            return self::FAILURE;
        }

        $businessId = $this->option('business') ?: (string) Str::uuid();

        if (Business::find($businessId)) {
            $this->error("Business '{$businessId}' already exists — pass a different --business or omit it to generate a fresh one.");

            return self::FAILURE;
        }

        Tenant::create([
            'id' => $businessId,
            'business_name' => 'Sync Simulator Business',
            'owner_email' => "sync-sim-{$businessId}@example.com",
        ]);
        Business::create([
            'id' => $businessId,
            'name' => 'Sync Simulator Business',
            'currency_code' => 'USD',
            'accounting_go_live_date' => now()->toDateString(),
        ]);
        (new ChartOfAccountsSeeder)->seedForBusiness($businessId);

        $owner = User::factory()->create([
            'email' => "sync-sim-owner-{$businessId}@example.com",
        ]);

        $devices = [];
        foreach (['TILL-001', 'TILL-002', 'MANAGER-001'] as $label) {
            $plainToken = $owner->createToken("sync-simulator-{$label}")->plainTextToken;
            $tokenId = (int) explode('|', $plainToken)[0];
            $deviceIdentifier = (string) Str::uuid();

            Device::create([
                'tenant_id' => $businessId,
                'name' => "Sync Simulator {$label}",
                'device_identifier' => $deviceIdentifier,
                'token_id' => $tokenId,
                'is_revoked' => false,
            ]);

            $devices[] = [
                'label' => $label,
                'device_identifier' => $deviceIdentifier,
                'token' => $plainToken,
            ];
        }

        $output = [
            'business_id' => $businessId,
            'devices' => $devices,
        ];

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
