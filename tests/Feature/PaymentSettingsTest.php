<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeviceToken(string $tenantId): string
    {
        Tenant::create([
            'id' => $tenantId,
            'business_name' => 'Test Business '.$tenantId,
            'owner_email' => $tenantId.'@example.com',
            'tier' => 'pro',
            'subscription_valid_until' => now()->addMonth(),
        ]);

        $user = User::factory()->create();

        $plain = $user->createToken('test-device')->plainTextToken;
        $tokenId = (int) explode('|', $plain)[0];

        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Device',
            'device_identifier' => str()->uuid(),
            'token_id' => $tokenId,
            'is_revoked' => false,
        ]);

        return $plain;
    }

    public function test_admin_can_view_payment_settings_page(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->get('/settings/payments')
            ->assertOk();
    }

    public function test_admin_can_update_payment_settings(): void
    {
        $admin = User::factory()->create();

        $response = $this->actingAs($admin)->put('/settings/payments', [
            'ecocash_number' => '0771234567',
            'ecocash_name' => 'SmartPOS Ltd',
            'whatsapp_number' => '+263771234567',
            'instructions' => 'Use your business name as the reference.',
        ]);

        $response->assertRedirect('/settings/payments');

        $this->assertSame('0771234567', Setting::get(Setting::PAYMENT_ECOCASH_NUMBER));
        $this->assertSame('SmartPOS Ltd', Setting::get(Setting::PAYMENT_ECOCASH_NAME));
        $this->assertSame('+263771234567', Setting::get(Setting::PAYMENT_WHATSAPP_NUMBER));
        $this->assertSame('Use your business name as the reference.', Setting::get(Setting::PAYMENT_INSTRUCTIONS));
    }

    public function test_admin_can_clear_payment_settings(): void
    {
        Setting::set(Setting::PAYMENT_ECOCASH_NUMBER, '0771234567');

        $admin = User::factory()->create();

        $this->actingAs($admin)
            ->put('/settings/payments', [
                'ecocash_number' => null,
                'ecocash_name' => null,
                'whatsapp_number' => null,
                'instructions' => null,
            ])
            ->assertRedirect('/settings/payments');

        $this->assertNull(Setting::get(Setting::PAYMENT_ECOCASH_NUMBER));
    }

    public function test_guest_cannot_update_payment_settings(): void
    {
        $this->put('/settings/payments', ['ecocash_number' => '0779999999'])
            ->assertRedirect('/login');

        $this->assertNull(Setting::get(Setting::PAYMENT_ECOCASH_NUMBER));
    }

    public function test_subscription_status_includes_payment_info(): void
    {
        Setting::set(Setting::PAYMENT_ECOCASH_NUMBER, '0771234567');
        Setting::set(Setting::PAYMENT_ECOCASH_NAME, 'SmartPOS Ltd');
        Setting::set(Setting::PAYMENT_WHATSAPP_NUMBER, '+263771234567');

        $token = $this->makeDeviceToken('tenant-payment-info');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscription/status')
            ->assertOk()
            ->assertJson([
                'payment_info' => [
                    'ecocash_number' => '0771234567',
                    'ecocash_name' => 'SmartPOS Ltd',
                    'whatsapp_number' => '+263771234567',
                    'instructions' => null,
                ],
            ]);
    }

    public function test_subscription_status_payment_info_is_null_when_unset(): void
    {
        $token = $this->makeDeviceToken('tenant-payment-empty');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/subscription/status')
            ->assertOk()
            ->assertJson([
                'payment_info' => [
                    'ecocash_number' => null,
                    'whatsapp_number' => null,
                ],
            ]);
    }
}
