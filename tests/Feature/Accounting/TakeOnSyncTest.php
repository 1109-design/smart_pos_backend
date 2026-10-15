<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\TakeOnBatch;
use App\Models\Accounting\TakeOnRow;
use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Take-on batches (Phase 3) are captured, approved and posted on a device;
 * the server stores them, insists on an approval by someone other than the
 * uploader before their journals are accepted, and stops accepting
 * take-on journals once take-on is finalised.
 */
class TakeOnSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-take-on';

    private Device $device;

    private User $owner;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-07-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);

        $this->owner = User::factory()->create(['business_id' => self::BIZ]);
        $this->owner->assignRole('business_owner');
        $this->token = $this->owner->createToken('t')->plainTextToken;
        $this->device = Device::create([
            'tenant_id' => self::BIZ, 'name' => 'Till', 'device_identifier' => (string) Str::uuid(),
            'token_id' => (int) explode('|', $this->token)[0], 'is_revoked' => false,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $table, string $uuid, array $payload): TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table, 'uuid' => $uuid, 'operation' => 'upsert',
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function batch(string $status, array $extra = []): array
    {
        return [
            'business_id' => self::BIZ, 'type' => 'gl', 'status' => $status, 'source' => 'import',
            'file_name' => 'tb.csv', 'row_count' => 1, 'totals' => ['USD' => ['debit' => 10, 'credit' => 10]],
            'uploaded_by_user_id' => $this->owner->id, 'updated_at' => now()->toIso8601String(), ...$extra,
        ];
    }

    private function approval(?string $approverId): ApprovalRequest
    {
        return ApprovalRequest::create([
            'id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'subject_type' => 'TakeOnBatch',
            'subject_id' => 'x', 'action' => 'take_on_batch', 'requested_by_user_id' => $this->owner->id,
            'status' => 'approved', 'approver_user_id' => $approverId,
        ]);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function takeOnJournal(string $batchId): array
    {
        $id = (string) Str::uuid();
        $cash = GlAccount::where('business_id', self::BIZ)->where('code', '1000')->value('id');
        $clearing = GlAccount::where('business_id', self::BIZ)->where('code', '3025')->value('id');

        return [$id, [
            'business_id' => self::BIZ,
            'header' => [
                'journal_number' => 'JNL-001-202606-'.substr($id, 0, 4), 'trans_date' => '2026-06-30',
                'source_type' => 'take_on', 'source_id' => $id, 'posting_key' => "take_on:{$batchId}:{$id}",
                'batch_id' => $batchId, 'journal_type' => 'take_on',
                'posted_device_id' => $this->device->device_identifier, 'status' => 'posted',
            ],
            'lines' => [
                ['id' => (string) Str::uuid(), 'gl_account_id' => $cash, 'debit' => 10, 'credit' => 0],
                ['id' => (string) Str::uuid(), 'gl_account_id' => $clearing, 'debit' => 0, 'credit' => 10],
            ],
        ]];
    }

    public function test_the_chart_has_take_on_clearing_and_supplier_prepayments(): void
    {
        $roles = app(AccountRoleMappingService::class);

        $this->assertSame('3025', $roles->resolve(self::BIZ, 'take_on_clearing')->code);
        $this->assertSame('1170', $roles->resolve(self::BIZ, 'supplier_prepayments')->code);
    }

    public function test_a_draft_batch_and_its_rows_sync(): void
    {
        $id = (string) Str::uuid();
        $this->push('take_on_batches', $id, $this->batch('pending_approval'));
        $rowId = (string) Str::uuid();
        $this->push('take_on_rows', $rowId, [
            'batch_id' => $id, 'business_id' => self::BIZ, 'row_no' => 1,
            'data' => ['account_code' => '1000', 'debit' => 10], 'status' => 'valid',
        ]);

        $this->assertSame('pending_approval', TakeOnBatch::findOrFail($id)->status);
        $this->assertSame('1000', TakeOnRow::findOrFail($rowId)->data['account_code']);
    }

    public function test_posting_a_batch_needs_an_approval(): void
    {
        $id = (string) Str::uuid();
        $response = $this->push('take_on_batches', $id, $this->batch('posting'));

        $this->assertStringContainsString('take_on_batch approval', (string) $response->json('errors.0.reason'));
        $this->assertNull(TakeOnBatch::find($id));
    }

    public function test_the_uploader_cannot_approve_their_own_batch(): void
    {
        $id = (string) Str::uuid();
        $approval = $this->approval($this->owner->id);

        $response = $this->push('take_on_batches', $id, $this->batch('posting', ['approval_request_id' => $approval->id]));

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNull(TakeOnBatch::find($id));
    }

    public function test_an_approved_batch_posts_its_journals(): void
    {
        $id = (string) Str::uuid();
        $approval = $this->approval((string) Str::uuid());
        $this->push('take_on_batches', $id, $this->batch('posting', ['approval_request_id' => $approval->id]));

        [$journalId, $bundle] = $this->takeOnJournal($id);
        $this->assertEmpty($this->push('journal_postings', $journalId, $bundle)->json('errors'));

        $this->assertSame('posted', JournalHeader::findOrFail($journalId)->status);
    }

    public function test_a_journal_of_an_unapproved_batch_is_rejected(): void
    {
        $id = (string) Str::uuid();
        $this->push('take_on_batches', $id, $this->batch('pending_approval'));

        [$journalId, $bundle] = $this->takeOnJournal($id);

        $this->assertSame('forbidden', $this->push('journal_postings', $journalId, $bundle)->json('errors.0.code'));
    }

    public function test_finalising_take_on_stops_take_on_journals_and_never_clears(): void
    {
        $id = (string) Str::uuid();
        $approval = $this->approval((string) Str::uuid());
        $this->push('take_on_batches', $id, $this->batch('posting', ['approval_request_id' => $approval->id]));

        $finalisedAt = now()->toIso8601String();
        $this->push('accounting_settings', self::BIZ, [
            'business_id' => self::BIZ, 'take_on_finalised_at' => $finalisedAt,
            'take_on_finalised_by_user_id' => $this->owner->id, 'updated_at' => now()->addSecond()->toIso8601String(),
        ]);
        $this->push('accounting_settings', self::BIZ, [
            'business_id' => self::BIZ, 'take_on_finalised_at' => null, 'updated_at' => now()->addSeconds(5)->toIso8601String(),
        ]);

        $business = Business::findOrFail(self::BIZ);
        $this->assertNotNull($business->take_on_finalised_at);
        $this->assertSame($this->owner->id, $business->take_on_finalised_by_user_id);
        $this->assertSame('2026-07-01', $business->accounting_go_live_date->toDateString());

        [$journalId, $bundle] = $this->takeOnJournal($id);
        $this->assertSame('take_on_finalised', $this->push('journal_postings', $journalId, $bundle)->json('errors.0.code'));
    }

    public function test_the_original_file_is_kept(): void
    {
        Storage::fake('local');
        $id = (string) Str::uuid();
        $this->push('take_on_batches', $id, $this->batch('pending_approval'));

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->post("/api/v1/take-on/batches/{$id}/file", ['file' => UploadedFile::fake()->createWithContent('tb.csv', "account_code,debit\n1000,10\n")])
            ->assertOk();

        $path = TakeOnBatch::findOrFail($id)->file_path;
        $this->assertSame('take-on/'.self::BIZ."/{$id}/tb.csv", $path);
        Storage::disk('local')->assertExists($path);
    }
}
