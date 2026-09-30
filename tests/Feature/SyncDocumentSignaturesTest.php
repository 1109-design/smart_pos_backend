<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\Device;
use App\Models\DocumentSignature;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * On-screen document signatures pushed from a till: stored once, only ever
 * voided afterwards, never deleted.
 */
class SyncDocumentSignaturesTest extends TestCase
{
    use RefreshDatabase;

    private function tokenFor(string $tenantId, string $role = 'cashier'): string
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        Business::create(['id' => $tenantId, 'name' => $tenantId, 'currency_code' => 'USD']);
        $user = User::factory()->create(['business_id' => $tenantId, 'email' => "{$tenantId}@example.com"]);
        $user->assignRole($role);

        $plain = $user->createToken('sync-test')->plainTextToken;
        Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Test Till',
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function push(string $token, string $uuid, array $payload, string $operation = 'upsert')
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'document_signatures',
                'uuid' => $uuid,
                'operation' => $operation,
                'payload' => $payload,
                'updated_at' => now()->toIso8601String(),
            ]]]);
    }

    private function payload(string $tenantId, array $overrides = []): array
    {
        return $overrides + [
            'business_id' => $tenantId,
            'document_type' => 'grv',
            'document_id' => (string) Str::uuid(),
            'slot' => 'received_by',
            'signer_name' => 'Tendai',
            'image_png' => base64_encode('ink-a'),
            'signed_at' => now()->toIso8601String(),
            'voided_at' => null,
        ];
    }

    public function test_a_signature_is_stored_and_its_ink_never_changes(): void
    {
        $tenantId = 'tenant-sign-1';
        $token = $this->tokenFor($tenantId);
        $uuid = (string) Str::uuid();
        $payload = $this->payload($tenantId);

        $this->push($token, $uuid, $payload)->assertOk();
        $this->push($token, $uuid, ['image_png' => base64_encode('ink-b'), 'signer_name' => 'Someone else'] + $payload)->assertOk();

        $row = DocumentSignature::findOrFail($uuid);
        $this->assertSame(base64_encode('ink-a'), $row->image_png);
        $this->assertSame('Tendai', $row->signer_name);
        $this->assertNull($row->voided_at);
    }

    public function test_a_signature_can_be_voided_but_not_deleted(): void
    {
        $tenantId = 'tenant-sign-2';
        $token = $this->tokenFor($tenantId);
        $uuid = (string) Str::uuid();
        $payload = $this->payload($tenantId);

        $this->push($token, $uuid, $payload)->assertOk();
        $this->push($token, $uuid, ['voided_at' => now()->toIso8601String()] + $payload)->assertOk();
        $this->push($token, $uuid, $payload, 'delete')->assertOk();

        $this->assertNotNull(DocumentSignature::findOrFail($uuid)->voided_at);
    }

    private function pushSetting(string $token, string $tenantId, bool $on)
    {
        return $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/sync/push', ['records' => [[
                'table' => 'pending_book_settings',
                'uuid' => $tenantId,
                'operation' => 'upsert',
                'payload' => ['business_id' => $tenantId, 'require_collection_signature' => $on ? 1 : 0],
                'updated_at' => now()->toIso8601String(),
            ]]]);
    }

    public function test_a_manager_cannot_require_collection_signatures(): void
    {
        $this->pushSetting($this->tokenFor('tenant-sign-3', 'manager'), 'tenant-sign-3', true)
            ->assertJsonCount(1, 'errors');
        $this->assertNull(DB::table('pending_book_settings')->where('business_id', 'tenant-sign-3')->first());
    }

    public function test_the_owner_can_require_collection_signatures(): void
    {
        $this->pushSetting($this->tokenFor('tenant-sign-4', 'business_owner'), 'tenant-sign-4', true)
            ->assertJsonCount(1, 'accepted');
        $this->assertTrue((bool) DB::table('pending_book_settings')->where('business_id', 'tenant-sign-4')->value('require_collection_signature'));
    }
}
