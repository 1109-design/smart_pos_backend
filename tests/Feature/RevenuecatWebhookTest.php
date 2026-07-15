<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevenuecatWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function upgradeEvent(string $tenantId): array
    {
        return [
            'event' => [
                'app_user_id' => $tenantId,
                'type' => 'INITIAL_PURCHASE',
                'product_id' => 'smartpos_ultimate_monthly',
                'expiration_at_ms' => now()->addMonth()->getTimestampMs(),
            ],
        ];
    }

    private function makeTenant(string $id): Tenant
    {
        return Tenant::create([
            'id' => $id,
            'business_name' => 'Webhook Test Business',
            'owner_email' => $id.'@example.com',
            'tier' => 'starter',
        ]);
    }

    public function test_webhook_rejects_requests_when_no_auth_is_configured(): void
    {
        config(['services.revenuecat.webhook_auth' => null]);
        $this->makeTenant('tenant-webhook-1');

        $response = $this->postJson(
            '/api/v1/webhooks/revenuecat',
            $this->upgradeEvent('tenant-webhook-1'),
        );

        $response->assertUnauthorized();
        $this->assertDatabaseHas('tenants', ['id' => 'tenant-webhook-1', 'tier' => 'starter']);
    }

    public function test_webhook_rejects_requests_with_wrong_auth_header(): void
    {
        config(['services.revenuecat.webhook_auth' => 'secret-token']);
        $this->makeTenant('tenant-webhook-2');

        $response = $this->withHeader('Authorization', 'wrong-token')->postJson(
            '/api/v1/webhooks/revenuecat',
            $this->upgradeEvent('tenant-webhook-2'),
        );

        $response->assertUnauthorized();
        $this->assertDatabaseHas('tenants', ['id' => 'tenant-webhook-2', 'tier' => 'starter']);
    }

    public function test_webhook_processes_event_with_correct_auth_header(): void
    {
        config(['services.revenuecat.webhook_auth' => 'secret-token']);
        $this->makeTenant('tenant-webhook-3');

        $response = $this->withHeader('Authorization', 'secret-token')->postJson(
            '/api/v1/webhooks/revenuecat',
            $this->upgradeEvent('tenant-webhook-3'),
        );

        $response->assertOk();
        $this->assertDatabaseHas('tenants', ['id' => 'tenant-webhook-3', 'tier' => 'ultimate']);
        $this->assertDatabaseHas('subscription_history', [
            'tenant_id' => 'tenant-webhook-3',
            'event_type' => 'INITIAL_PURCHASE',
        ]);
    }
}
