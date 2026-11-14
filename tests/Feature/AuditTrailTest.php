<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The owner's audit trail — see AuditController. */
class AuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private function deviceToken(string $tenantId, string $name): string
    {
        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            Tenant::create(['id' => $tenantId, 'business_name' => $tenantId, 'owner_email' => $tenantId.'@example.com']);
        }
        $user = User::factory()->create(['email' => $tenantId.'-'.Str::random(6).'@example.com']);
        $plain = $user->createToken('audit-test')->plainTextToken;

        Device::create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $plain)[0],
            'is_revoked' => false,
        ]);

        return $plain;
    }

    private function entry(int $seq, array $overrides = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'seq' => $seq,
            'occurred_at' => sprintf('2026-10-16T08:%02d:00.000Z', $seq % 60),
            'kind' => 'event',
            'action' => 'cart_item_removed',
            'category' => 'cart',
            'important' => true,
            'summary' => "Removed item {$seq}",
            'details' => ['product_name' => 'Cement'],
            'actor' => 'user',
            'user_id' => 'u-1',
            'user_name' => 'Tendai',
            'user_role' => 'cashier',
        ], $overrides);
    }

    public function test_push_is_idempotent_and_keeps_everything(): void
    {
        $token = $this->deviceToken('biz-1', 'Till 2');
        $batch = [$this->entry(1), $this->entry(2)];

        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => $batch])
            ->assertOk()->assertJsonCount(2, 'accepted');
        // Lost response, sent again: no duplicates, no false gap.
        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => $batch])->assertOk();

        $this->assertSame(2, AuditEntry::count());
        $e = AuditEntry::where('seq', 1)->first();
        $this->assertSame('biz-1', $e->business_id);
        $this->assertSame('Till 2', $e->device_name);
        $this->assertSame('Cement', $e->details['product_name']);
    }

    public function test_malformed_entry_does_not_block_the_rest(): void
    {
        $token = $this->deviceToken('biz-1', 'Till 2');

        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => [
            ['id' => 'not-a-uuid', 'seq' => 1],
            $this->entry(1, ['action' => str_repeat('x', 300), 'details' => 'not a map']),
        ]])->assertOk()->assertJsonCount(1, 'accepted');

        $this->assertSame(1, AuditEntry::count());
        $this->assertSame(64, strlen(AuditEntry::first()->action));
    }

    public function test_gap_in_numbering_is_flagged(): void
    {
        $token = $this->deviceToken('biz-1', 'Till 2');

        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(1), $this->entry(2)]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(6)]])->assertOk();

        $gap = AuditEntry::where('kind', 'integrity')->first();
        $this->assertNotNull($gap);
        $this->assertSame('entries_missing', $gap->action);
        $this->assertSame(['from_seq' => 3, 'to_seq' => 5], $gap->details);
        $this->assertStringContainsString('3 log entries from Till 2', $gap->summary);
    }

    public function test_restarted_numbering_is_flagged_once(): void
    {
        $token = $this->deviceToken('biz-1', 'Till 2');

        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(1), $this->entry(2), $this->entry(3)]])->assertOk();
        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(1), $this->entry(2)]])->assertOk();

        $this->assertSame(1, AuditEntry::where('action', 'log_restarted')->count());
        $this->assertSame(0, AuditEntry::where('action', 'entries_missing')->count());
    }

    public function test_devices_number_independently(): void
    {
        $a = $this->deviceToken('biz-1', 'Till 1');
        $b = $this->deviceToken('biz-1', 'Till 2');

        $this->withToken($a)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(1), $this->entry(2)]])->assertOk();
        $this->withToken($b)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(1)]])->assertOk();

        $this->assertSame(0, AuditEntry::where('kind', 'integrity')->count());
    }

    public function test_index_filters_pages_and_counts(): void
    {
        $till = $this->deviceToken('biz-1', 'Till 2');
        $owner = $this->deviceToken('biz-1', 'Office');
        $other = $this->deviceToken('biz-2', 'Elsewhere');

        $entries = [];
        for ($i = 1; $i <= 5; $i++) {
            $entries[] = $this->entry($i);
        }
        $entries[] = $this->entry(6, ['action' => 'login', 'category' => 'security', 'summary' => 'Signed in', 'user_id' => 'u-2', 'user_name' => 'Rudo']);
        $entries[] = $this->entry(7, ['kind' => 'data_change', 'action' => 'saved', 'category' => 'stock', 'important' => false, 'summary' => 'Product stock']);
        $this->withToken($till)->postJson('/api/v1/audit/push', ['entries' => $entries])->assertOk();
        $this->withToken($other)->postJson('/api/v1/audit/push', ['entries' => [$this->entry(1)]])->assertOk();

        // Another business's entries never show; unimportant ones only on request.
        $res = $this->withToken($owner)->getJson('/api/v1/audit?meta=1&limit=4')->assertOk();
        $res->assertJsonCount(4, 'entries')
            ->assertJsonPath('entries.0.summary', 'Signed in')
            ->assertJsonPath('counts.cart_item_removed', 5)
            ->assertJsonPath('counts.login', 1)
            ->assertJsonCount(2, 'users');
        $this->assertNotNull($res->json('next_cursor'));

        $page2 = $this->withToken($owner)->getJson('/api/v1/audit?limit=4&cursor='.urlencode($res->json('next_cursor')))->assertOk();
        $page2->assertJsonCount(2, 'entries')->assertJsonPath('next_cursor', null);

        $this->withToken($owner)->getJson('/api/v1/audit?important_only=0')->assertOk()->assertJsonCount(7, 'entries');
        $this->withToken($owner)->getJson('/api/v1/audit?user_id=u-2')->assertOk()->assertJsonCount(1, 'entries');
        $this->withToken($owner)->getJson('/api/v1/audit?category=security')->assertOk()->assertJsonCount(1, 'entries');
        $this->withToken($owner)->getJson('/api/v1/audit?q=item%203')->assertOk()->assertJsonCount(1, 'entries');
        $this->withToken($owner)->getJson('/api/v1/audit?from=2026-10-16T08:03:00Z&to=2026-10-16T08:05:00Z')
            ->assertOk()->assertJsonCount(2, 'entries');
    }

    public function test_data_change_carries_the_previous_version(): void
    {
        $token = $this->deviceToken('biz-1', 'Till 2');
        $change = fn (int $seq, float $price) => $this->entry($seq, [
            'kind' => 'data_change', 'action' => 'saved', 'category' => 'products',
            'table_name' => 'products', 'record_id' => 'p-1', 'summary' => 'Product “Cement”',
            'details' => ['operation' => 'upsert', 'payload' => ['name' => 'Cement', 'price' => $price]],
        ]);

        $this->withToken($token)->postJson('/api/v1/audit/push', ['entries' => [$change(1, 10.0), $change(2, 8.5)]])->assertOk();

        $this->withToken($token)->getJson('/api/v1/audit?table_name=products')
            ->assertOk()
            ->assertJsonPath('entries.0.details.payload.price', 8.5)
            ->assertJsonPath('entries.0.previous.details.payload.price', 10)
            ->assertJsonPath('entries.1.previous', null);
    }

    public function test_unknown_device_is_refused(): void
    {
        $this->postJson('/api/v1/audit/push', ['entries' => []])->assertUnauthorized();
    }
}
