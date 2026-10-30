<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A delete stored with an empty payload used to reach devices as `[]`,
 * which every app version skipped for good — the deleted row stayed put.
 */
class EmptyDeletePayloadTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-empty-delete';

    private function deviceToken(): string
    {
        Tenant::firstOrCreate(['id' => $this->tenantId], [
            'business_name' => 'Empty Delete Biz',
            'owner_email' => $this->tenantId.'@example.com',
        ]);
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

    private function emptyDelete(string $table, string $uuid): SyncRecord
    {
        return SyncRecord::create([
            'business_id' => $this->tenantId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'delete',
            'payload' => [],
            'synced_at' => now()->subDay(),
        ]);
    }

    public function test_an_empty_payload_goes_out_as_an_object(): void
    {
        $token = $this->deviceToken();
        $this->emptyDelete('approval_rules', 'rule-1');

        $raw = $this->withHeader('Authorization', 'Bearer '.$token)
            ->get('/api/v1/sync/pull?tables[]=approval_rules', ['Accept' => 'application/json'])
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('"payload":{}', $raw);
        $this->assertStringNotContainsString('"payload":[]', $raw);
    }

    public function test_the_command_resends_skipped_deletes_but_not_rewritten_ones(): void
    {
        $this->emptyDelete('approval_rules', 'rule-gone');
        $this->emptyDelete('quotation_items', 'item-back');
        SyncRecord::create([
            'business_id' => $this->tenantId,
            'table_name' => 'quotation_items',
            'record_uuid' => 'item-back',
            'operation' => 'upsert',
            'payload' => ['quotation_id' => 'q-1'],
            'synced_at' => now(),
        ]);

        $this->artisan('sync:reemit-empty-deletes', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, SyncRecord::where('record_uuid', 'rule-gone')->count());

        $this->artisan('sync:reemit-empty-deletes')->assertSuccessful();

        $resent = SyncRecord::where('record_uuid', 'rule-gone')->orderByDesc('id')->first();
        $this->assertSame(2, SyncRecord::where('record_uuid', 'rule-gone')->count());
        $this->assertSame('delete', $resent->operation);
        $this->assertNotEmpty($resent->payload);
        // Written again after its delete — re-sending would wipe it.
        $this->assertSame(1, SyncRecord::where('record_uuid', 'item-back')->where('operation', 'delete')->count());
    }
}
