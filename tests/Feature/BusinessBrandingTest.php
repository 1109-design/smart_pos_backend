<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Business;
use App\Models\Device;
use App\Models\DocumentBrandingSetting;
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

    public function test_device_can_upload_letterhead_and_footer_image(): void
    {
        Storage::fake('public');

        $tenantId = 'tenant-branding-7';
        $token = $this->actingDeviceToken($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Letterhead Shop']);

        $letterhead = UploadedFile::fake()->image('letterhead.png', 800, 200);
        $footer = UploadedFile::fake()->image('footer.png', 800, 100);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/business/letterhead', ['letterhead' => $letterhead])
            ->assertOk()->assertJsonStructure(['letterhead_url']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->post('/api/v1/business/footer-image', ['footer' => $footer])
            ->assertOk()->assertJsonStructure(['footer_url']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson('/api/v1/business/footer-text', ['footer_text' => 'Thank you for your business. Bank: CBZ 123456.'])
            ->assertOk();

        $business = Business::find($tenantId);
        Storage::disk('public')->assertExists($business->letterhead_path);
        Storage::disk('public')->assertExists($business->footer_path);
        $this->assertSame('Thank you for your business. Bank: CBZ 123456.', $business->footer_text);

        // letterhead/footer images travel via the pull-only business_branding
        // record, same as the logo — one row per upload.
        $this->assertSame(2, SyncRecord::where('business_id', $tenantId)->where('table_name', 'business_branding')->count());
    }

    /**
     * Guards the same footgun class as
     * test_device_push_does_not_touch_server_logo_path_or_primary_color()
     * above, extended to the two new path fields.
     */
    public function test_device_push_does_not_touch_server_letterhead_or_footer_path(): void
    {
        $tenantId = 'tenant-branding-8';
        $token = $this->actingDeviceToken($tenantId);

        Business::create([
            'id' => $tenantId,
            'name' => 'Guarded Shop',
            'letterhead_path' => 'letterheads/'.$tenantId.'/real-letterhead.png',
            'footer_path' => 'footers/'.$tenantId.'/real-footer.png',
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => $tenantId,
                    'operation' => 'upsert',
                    'payload' => [
                        'name' => 'Guarded Shop',
                        'letterhead_path' => '/data/user/0/com.example.smart_pos/app_flutter/letterheads/local.png',
                        'footer_path' => '/data/user/0/com.example.smart_pos/app_flutter/footers/local.png',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'letterhead_path' => 'letterheads/'.$tenantId.'/real-letterhead.png',
            'footer_path' => 'footers/'.$tenantId.'/real-footer.png',
        ]);
    }

    public function test_footer_text_syncs_through_the_generic_businesses_push(): void
    {
        $tenantId = 'tenant-branding-9';
        $token = $this->actingDeviceToken($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Footer Text Shop']);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', [
                'records' => [[
                    'table' => 'businesses',
                    'uuid' => $tenantId,
                    'operation' => 'upsert',
                    'payload' => [
                        'name' => 'Footer Text Shop',
                        'footer_text' => 'Terms: goods sold are not returnable.',
                    ],
                    'updated_at' => now()->toIso8601String(),
                ]],
            ])->assertOk();

        $this->assertDatabaseHas('businesses', [
            'id' => $tenantId,
            'footer_text' => 'Terms: goods sold are not returnable.',
        ]);
    }

    public function test_backoffice_owner_can_update_document_branding_settings(): void
    {
        $tenantId = 'tenant-branding-10';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Doc Settings Shop']);

        $this->post('/office/settings/document-branding', [
            'document_type' => 'invoice',
            'use_letterhead' => true,
            'use_footer' => false,
            'show_logo' => true,
            'paper_size' => 'A4',
        ])->assertRedirect();

        $this->assertDatabaseHas('document_branding_settings', [
            'business_id' => $tenantId,
            'document_type' => 'invoice',
            'use_letterhead' => true,
            'use_footer' => false,
        ]);
    }

    public function test_backoffice_owner_can_update_delivery_note_branding_settings(): void
    {
        $tenantId = 'tenant-branding-delivery-note';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Delivery Note Shop']);

        $this->post('/office/settings/document-branding', [
            'document_type' => 'delivery_note',
            'use_letterhead' => true,
            'use_footer' => true,
            'show_logo' => true,
            'paper_size' => 'A4',
        ])->assertRedirect();

        $this->assertDatabaseHas('document_branding_settings', [
            'business_id' => $tenantId,
            'document_type' => 'delivery_note',
            'use_letterhead' => true,
            'use_footer' => true,
        ]);
    }

    public function test_document_branding_settings_defaults_when_unconfigured(): void
    {
        $tenantId = 'tenant-branding-11';
        $this->actingBackOfficeSession($tenantId);
        Business::create(['id' => $tenantId, 'name' => 'Default Settings Shop']);

        $response = $this->get('/office/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('document_branding_settings.sales_receipt.paper_size', '80mm')
            ->where('document_branding_settings.invoice.paper_size', 'A4')
            ->where('document_branding_settings.invoice.use_letterhead', true)
        );
    }

    /**
     * Cross-business isolation — the core acceptance criterion of the whole
     * document branding feature: a user on business B must never see or
     * touch business A's letterhead/footer/document settings.
     */
    public function test_document_branding_settings_are_isolated_per_business(): void
    {
        $businessA = 'tenant-branding-iso-a';
        $businessB = 'tenant-branding-iso-b';

        Business::create(['id' => $businessA, 'name' => 'Business A', 'letterhead_path' => 'letterheads/a/lh.png']);
        Business::create(['id' => $businessB, 'name' => 'Business B']);

        DocumentBrandingSetting::create([
            'business_id' => $businessA,
            'document_type' => 'invoice',
            'use_letterhead' => true,
            'use_footer' => true,
            'show_logo' => true,
            'paper_size' => 'A4',
        ]);

        $this->actingBackOfficeSession($businessB);
        $response = $this->get('/office/settings');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('branding.letterhead_url', null)
            ->where('document_branding_settings.invoice.use_letterhead', true) // system default, not A's row
        );

        $this->assertDatabaseMissing('document_branding_settings', [
            'business_id' => $businessB,
            'document_type' => 'invoice',
        ]);
    }
}
