<?php

namespace Tests\Feature;

use App\Models\AppRelease;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    private function upload(string $name, string $version = 'v1.0.58', string $platform = 'windows', string $token = 'ci-secret')
    {
        return $this->withHeader('X-Release-Token', $token)
            ->post('/api/v1/app-releases/upload', [
                'platform' => $platform,
                'version' => $version,
                'file' => UploadedFile::fake()->create($name, 100),
            ], ['Accept' => 'application/json']);
    }

    public function test_an_uploaded_installer_is_hosted_and_reaches_devices(): void
    {
        Storage::fake('public');

        $this->upload('SmartPOS-Setup-1.0.58+1.exe')->assertOk()->assertJson(['updated' => true]);

        Storage::disk('public')->assertExists('releases/windows/SmartPOS-Setup-1.0.58+1.exe');
        $pull = $this->withHeader('Authorization', 'Bearer '.$this->deviceToken())
            ->getJson('/api/v1/sync/pull');
        $this->assertSame('v1.0.58', $pull->json('app_releases.windows.version'));
        $this->assertSame('SmartPOS-Setup-1.0.58+1.exe', $pull->json('app_releases.windows.asset_name'));
        $this->assertStringEndsWith('/storage/releases/windows/SmartPOS-Setup-1.0.58%2B1.exe', $pull->json('app_releases.windows.download_url'));
    }

    public function test_a_new_upload_deletes_the_previous_installer(): void
    {
        Storage::fake('public');
        $this->upload('SmartPOS-Setup-1.0.58+1.exe')->assertOk();
        $this->upload('smart-pos_1.0.58_amd64.deb', 'v1.0.58', 'linux')->assertOk();

        $this->upload('SmartPOS-Setup-1.0.59+1.exe', 'v1.0.59')->assertOk();

        $disk = Storage::disk('public');
        $disk->assertMissing('releases/windows/SmartPOS-Setup-1.0.58+1.exe');
        $disk->assertExists('releases/windows/SmartPOS-Setup-1.0.59+1.exe');
        // Other platforms' installers are untouched.
        $disk->assertExists('releases/linux/smart-pos_1.0.58_amd64.deb');
    }

    public function test_an_older_upload_is_ignored_and_not_stored(): void
    {
        Storage::fake('public');
        $this->upload('SmartPOS-Setup-1.0.59+1.exe', 'v1.0.59')->assertOk();

        $this->upload('SmartPOS-Setup-1.0.58+1.exe', 'v1.0.58')->assertOk()->assertJson(['updated' => false]);

        Storage::disk('public')->assertMissing('releases/windows/SmartPOS-Setup-1.0.58+1.exe');
        Storage::disk('public')->assertExists('releases/windows/SmartPOS-Setup-1.0.59+1.exe');
        $this->assertSame('v1.0.59', AppRelease::find('windows')->version);
    }

    public function test_uploads_need_the_secret_and_the_platforms_file_type(): void
    {
        Storage::fake('public');

        $this->upload('SmartPOS-Setup-1.0.58+1.exe', token: 'wrong')->assertStatus(401);
        $this->upload('SmartPOS-1.0.58.apk')->assertStatus(422);
        $this->upload('Smart POS Setup.exe')->assertStatus(422);

        $this->assertDatabaseCount('app_releases', 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }
}
