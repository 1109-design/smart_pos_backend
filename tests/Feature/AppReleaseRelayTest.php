<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CI reports each published installer; every device learns of it on its
 * next /sync/pull instead of polling GitHub's rate-limited API.
 */
class AppReleaseRelayTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-app-release';

    private const URL = 'https://github.com/h200137j/smart_pos_releases/releases/download/v1.0.55/SmartPOS-Setup-1.0.55+1.exe';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.app_releases.token' => 'ci-secret']);
        Tenant::create([
            'id' => $this->tenantId,
            'business_name' => 'Release Test Biz',
            'owner_email' => $this->tenantId.'@example.com',
        ]);
    }

    private function deviceToken(): string
    {
        $user = User::factory()->create();
        $plain = $user->createToken('till')->plainTextToken;
        Device::create([
            'tenant_id' => $this->tenantId,
            'name' => 'till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function report(array $overrides = [], string $token = 'ci-secret')
    {
        return $this->withHeader('X-Release-Token', $token)
            ->postJson('/api/v1/app-releases', array_merge([
                'platform' => 'windows',
                'version' => 'v1.0.55',
                'asset_name' => 'SmartPOS-Setup-1.0.55+1.exe',
                'download_url' => self::URL,
            ], $overrides));
    }

    public function test_a_reported_release_reaches_devices_on_sync_pull(): void
    {
        $this->report()->assertOk()->assertJson(['updated' => true]);

        $pull = $this->withHeader('Authorization', 'Bearer '.$this->deviceToken())
            ->getJson('/api/v1/sync/pull');

        $pull->assertOk();
        $this->assertSame('v1.0.55', $pull->json('app_releases.windows.version'));
        $this->assertSame(self::URL, $pull->json('app_releases.windows.download_url'));
    }

    public function test_reports_without_the_secret_are_refused(): void
    {
        $this->report([], 'wrong')->assertStatus(401);
        config(['services.app_releases.token' => null]);
        $this->report([], '')->assertStatus(401);
        $this->assertDatabaseCount('app_releases', 0);
    }

    public function test_only_installers_from_the_public_releases_repo_are_accepted(): void
    {
        $this->report(['download_url' => 'https://evil.example/SmartPOS.exe'])
            ->assertStatus(422);
        $this->assertDatabaseCount('app_releases', 0);
    }

    public function test_an_older_version_never_rolls_devices_back(): void
    {
        $this->report()->assertOk();
        $this->report([
            'version' => 'v1.0.54',
            'download_url' => str_replace('1.0.55', '1.0.54', self::URL),
        ])->assertOk()->assertJson(['updated' => false]);

        $pull = $this->withHeader('Authorization', 'Bearer '.$this->deviceToken())
            ->getJson('/api/v1/app-releases');
        $this->assertSame('v1.0.55', $pull->json('app_releases.windows.version'));
    }
}
