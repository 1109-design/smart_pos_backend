<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Business;
use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class BusinessBrandingTest extends TestCase
{
    use RefreshDatabase;

    private function actingDeviceToken(string $tenantId): string
    {
        Tenant::firstOrCreate(['id' => $tenantId], ['business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);

        $user = User::factory()->create([
            'email' => $tenantId.'-device-owner@example.com',
        ]);

        $plain = $user->createToken('sync-test')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function actingBackOfficeSession(string $tenantId): User
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
                'role' => 'business_owner',
                'business_name' => $tenantId,
                'currency_code' => 'USD',
            ],
        ]);

        return $user;
    }

    /**
     * The exact regression this feature guards against: a device always
     * sends its own (opaque, on-device) logo_path in every 'businesses'
     * sync push (see business_sync.dart's businessSyncPayload()) — before
     * this fix, that silently overwrote whatever real public logo URL the
     * upload endpoint had set. logo_path/primary_color must now be entirely
     * outside the generic 'businesses' case's blast radius.
     */
    public function test_device_push_does_not_touch_server_logo_path_or_primary_color(): void
    {
        $tenantId = 'tenant-branding-1';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Branded Shop',
            'logo_path' => 'logos/'.$tenantId.'/real-logo.png',
            'primary_color' => '#112233',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => $tenantId,
                    'operation' => 'upsert',
                    'payload' => [
                        'name' => 'Branded Shop',
                        'logo_path' => '/data/user/0/com.example.smart_pos/app_flutter/logos/business_logo.jpg',
                        'phone' => '+263779999999',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'phone' => '+263779999999',
            'logo_path' => 'logos/'.$tenantId.'/real-logo.png',
            'primary_color' => '#112233',
        ]);
    }

    public function test_device_can_upload_a_logo(): void
    {
        Storage::fake('public');

        $tenantId = 'tenant-branding-2';
        $token = $this->actingDeviceToken($tenantId);

        Business::create(['id' => $tenantId, 'name' => 'Device Upload Shop']);

        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/business/logo', ['logo' => $file]);

        $response->assertOk();
        $response->assertJsonStructure(['logo_url']);

        $business = Business::find($tenantId);
        $this->assertNotNull($business->logo_path);
        Storage::disk('public')->assertExists($business->logo_path);

        $this->assertDatabaseHas('sync_records', [
            'business_id' => $tenantId,
            'table_name' => 'business_branding',
        ]);
        $this->assertSame(1, SyncRecord::where('business_id', $tenantId)->where('table_name', 'business_branding')->count());
    }

    public function test_backoffice_owner_can_set_brand_color(): void
    {
        $tenantId = 'tenant-branding-3';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Color Shop']);

        $this->post('/office/settings/branding', ['primary_color' => '#ff8800'])
            ->assertRedirect();

        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'primary_color' => '#ff8800',
        ]);
        $this->assertSame(1, SyncRecord::where('business_id', $tenantId)->where('table_name', 'business_branding')->count());
    }

    public function test_backoffice_rejects_an_invalid_color(): void
    {
        $tenantId = 'tenant-branding-4';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Bad Color Shop']);

        $this->post('/office/settings/branding', ['primary_color' => 'not-a-color'])
            ->assertSessionHasErrors('primary_color');
    }

    public function test_backoffice_owner_can_upload_a_logo(): void
    {
        Storage::fake('public');

        $tenantId = 'tenant-branding-5';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'BackOffice Upload Shop']);

        $file = UploadedFile::fake()->image('logo.jpg', 200, 200);

        $this->post('/office/settings/branding/logo', ['logo' => $file])->assertRedirect();

        $business = Business::find($tenantId);
        $this->assertNotNull($business->logo_path);
        Storage::disk('public')->assertExists($business->logo_path);
    }

    public function test_non_owner_cannot_update_branding(): void
    {
        $tenantId = 'tenant-branding-6';
        $this->actingBackOfficeSession($tenantId);
        session(['backoffice.role' => 'manager']);
        Business::create(['id' => $tenantId, 'name' => 'Manager Shop']);

        $this->post('/office/settings/branding', ['primary_color' => '#ff8800'])
            ->assertForbidden();
    }
}
