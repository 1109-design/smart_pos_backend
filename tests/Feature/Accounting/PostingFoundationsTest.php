<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 0 schema: device codes for document numbering, and posting_key /
 * journal_number uniqueness per business.
 */
class PostingFoundationsTest extends TestCase
{
    use RefreshDatabase;

    private function tenant(string $id): void
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => $id.'@example.com']);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD']);
    }

    private function device(string $tenantId): Device
    {
        return Device::create([
            'tenant_id' => $tenantId,
            'name' => 'Till',
            'device_identifier' => (string) Str::uuid(),
            'is_revoked' => false,
        ]);
    }

    private function journal(string $businessId, string $number, ?string $postingKey): JournalHeader
    {
        return JournalHeader::create([
            'business_id' => $businessId,
            'journal_number' => $number,
            'trans_date' => '2026-10-15',
            'status' => 'posted',
            'posting_key' => $postingKey,
        ]);
    }

    public function test_devices_get_sequential_codes_unique_within_their_tenant(): void
    {
        $this->tenant('biz-a');
        $this->tenant('biz-b');

        $codesA = collect(range(1, 3))->map(fn () => $this->device('biz-a')->device_code);
        $firstB = $this->device('biz-b')->device_code;

        $this->assertSame(['001', '002', '003'], $codesA->all());
        $this->assertSame('001', $firstB);
    }

    public function test_a_device_moved_to_another_tenant_takes_a_free_code_there(): void
    {
        $this->tenant('biz-a');
        $this->tenant('biz-b');
        $this->device('biz-a');
        $moving = $this->device('biz-b');
        $this->assertSame('001', $moving->device_code);

        $moving->update(['tenant_id' => 'biz-a']);

        $this->assertSame('002', $moving->fresh()->device_code);
    }

    public function test_codes_continue_in_base_36_past_nine(): void
    {
        $this->assertSame('009', Device::codeFor(9));
        $this->assertSame('00A', Device::codeFor(10));
        $this->assertSame('010', Device::codeFor(36));
        $this->assertSame('ZZZ', Device::codeFor(46655));
    }

    public function test_a_posting_key_can_only_be_used_once_per_business(): void
    {
        $this->tenant('biz-a');
        $this->tenant('biz-b');
        $this->journal('biz-a', 'JNL-001-202610-0001', 'sale:s1:post');
        // Another business may use the same key.
        $this->journal('biz-b', 'JNL-001-202610-0001', 'sale:s1:post');

        $this->expectException(QueryException::class);
        $this->journal('biz-a', 'JNL-001-202610-0002', 'sale:s1:post');
    }

    public function test_a_journal_number_can_only_be_used_once_per_business(): void
    {
        $this->tenant('biz-a');
        $this->journal('biz-a', 'JNL-001-202610-0001', 'sale:s1:post');

        $this->expectException(QueryException::class);
        $this->journal('biz-a', 'JNL-001-202610-0001', 'sale:s2:post');
    }
}
