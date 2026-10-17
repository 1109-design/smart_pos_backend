<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalAttachment;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalTemplate;
use App\Models\Accounting\ManualJournal;
use App\Models\ApprovalRequest;
use App\Models\Business;
use App\Models\Device;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * General journals (Phase 4) are captured, approved and posted on a
 * device. The server stores each with its lines, checks who may post it,
 * and accepts its ledger journal only once it is approved.
 */
class ManualJournalSyncTest extends TestCase
{
    use RefreshDatabase;

    private const BIZ = 'tenant-journals';

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::create(['id' => self::BIZ, 'business_name' => self::BIZ, 'owner_email' => self::BIZ.'@example.com']);
        Business::create(['id' => self::BIZ, 'name' => self::BIZ, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness(self::BIZ);
        $this->seed(RolesAndPermissionsSeeder::class);
        Role::firstOrCreate(['name' => 'accountant', 'guard_name' => 'web']);
        $this->device = Device::create(['tenant_id' => self::BIZ, 'name' => 'Till', 'device_identifier' => (string) Str::uuid(), 'is_revoked' => false]);
    }

    private function tokenFor(string $role): string
    {
        $user = User::factory()->create(['business_id' => self::BIZ]);
        $user->assignRole($role);
        $token = $user->createToken('t')->plainTextToken;
        $this->device->update(['token_id' => (int) explode('|', $token)[0]]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function push(string $token, string $table, string $uuid, array $payload, string $operation = 'upsert'): TestResponse
    {
        // Each push signs in afresh — the test client would otherwise keep
        // the first user for every request.
        $this->app['auth']->forgetGuards();

        return $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/v1/sync/push', ['records' => [[
            'table' => $table, 'uuid' => $uuid, 'operation' => $operation,
            'payload' => $payload, 'updated_at' => now()->toIso8601String(),
        ]]])->assertOk();
    }

    private function account(string $code): string
    {
        return GlAccount::where('business_id', self::BIZ)->where('code', $code)->value('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function journal(string $status, bool $customerLine = false, array $extra = []): array
    {
        return [
            'business_id' => self::BIZ, 'document_number' => 'MJ-001-202610-'.random_int(1000, 9999),
            'journal_type' => 'general', 'trans_date' => '2026-10-10', 'reference' => 'R1',
            'narration' => 'Rent', 'status' => $status, 'created_by_user_id' => (string) Str::uuid(),
            'updated_at' => now()->toIso8601String(),
            'lines' => [
                ['id' => (string) Str::uuid(), 'line_no' => 1, 'line_type' => $customerLine ? 'customer' : 'gl',
                    'gl_account_id' => $customerLine ? null : $this->account('6000'),
                    'party_id' => $customerLine ? (string) Str::uuid() : null, 'debit' => 100, 'credit' => 0],
                ['id' => (string) Str::uuid(), 'line_no' => 2, 'line_type' => 'gl', 'gl_account_id' => $this->account('1000'), 'debit' => 0, 'credit' => 100],
            ],
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ledgerBundle(string $manualJournalId): array
    {
        $id = (string) Str::uuid();

        return [$id, [
            'business_id' => self::BIZ,
            'header' => [
                'journal_number' => 'JNL-001-202610-'.substr($id, 0, 4), 'trans_date' => '2026-10-10',
                'source_type' => 'manual_journal', 'source_id' => $manualJournalId,
                'posting_key' => "manual_journal:{$manualJournalId}:post", 'journal_type' => 'general',
                'posted_device_id' => $this->device->device_identifier, 'status' => 'posted',
            ],
            'lines' => [
                ['id' => (string) Str::uuid(), 'gl_account_id' => $this->account('6000'), 'debit' => 100, 'credit' => 0],
                ['id' => (string) Str::uuid(), 'gl_account_id' => $this->account('1000'), 'debit' => 0, 'credit' => 100],
            ],
        ]];
    }

    public function test_a_draft_syncs_with_its_lines(): void
    {
        $id = (string) Str::uuid();
        $this->push($this->tokenFor('cashier'), 'manual_journals', $id, $this->journal('draft'));

        $journal = ManualJournal::findOrFail($id);
        $this->assertSame('draft', $journal->status);
        $this->assertCount(2, $journal->lines);
        $this->assertSame(100.0, $journal->lines->first()->debit);
    }

    public function test_posting_needs_the_gl_post_permission_or_an_approval(): void
    {
        $id = (string) Str::uuid();
        $response = $this->push($this->tokenFor('cashier'), 'manual_journals', $id, $this->journal('posted'));
        $this->assertStringContainsString('finance.gl.post', (string) $response->json('errors.0.reason'));
        $this->assertNull(ManualJournal::find($id));

        ApprovalRequest::create([
            'id' => (string) Str::uuid(), 'business_id' => self::BIZ, 'subject_type' => 'ManualJournal',
            'subject_id' => $id, 'action' => 'manual_journal_post', 'requested_by_user_id' => (string) Str::uuid(),
            'status' => 'approved',
        ]);
        $this->assertEmpty($this->push($this->tokenFor('cashier'), 'manual_journals', $id, $this->journal('posted'))->json('errors'));
        $this->assertSame('posted', ManualJournal::findOrFail($id)->status);
    }

    public function test_customer_lines_need_the_control_account_permission(): void
    {
        $id = (string) Str::uuid();
        $response = $this->push($this->tokenFor('accountant'), 'manual_journals', $id, $this->journal('posted', customerLine: true));
        $this->assertStringContainsString('post_control', (string) $response->json('errors.0.reason'));

        $this->assertEmpty($this->push($this->tokenFor('accountant'), 'manual_journals', (string) Str::uuid(), $this->journal('posted'))->json('errors'));
        $response = $this->push($this->tokenFor('manager'), 'manual_journals', $id, $this->journal('posted', customerLine: true));
        $this->assertEmpty($response->json('errors'), (string) $response->json('errors.0.reason'));
    }

    public function test_a_posted_journal_never_reopens_and_keeps_its_lines(): void
    {
        $owner = $this->tokenFor('business_owner');
        $id = (string) Str::uuid();
        $this->push($owner, 'manual_journals', $id, $this->journal('posted'));
        $lineIds = ManualJournal::findOrFail($id)->lines->pluck('id')->all();

        $response = $this->push($owner, 'manual_journals', $id, $this->journal('draft', extra: ['updated_at' => now()->addMinute()->toIso8601String()]));
        $this->assertStringContainsString('never reopened', (string) $response->json('errors.0.reason'));

        $this->push($owner, 'manual_journals', $id, $this->journal('reversed', extra: ['updated_at' => now()->addMinutes(2)->toIso8601String()]));
        $journal = ManualJournal::findOrFail($id);
        $this->assertSame('reversed', $journal->status);
        $this->assertSame($lineIds, $journal->lines->pluck('id')->all());
    }

    public function test_the_ledger_journal_posts_only_once_the_journal_is_approved(): void
    {
        $owner = $this->tokenFor('business_owner');
        $id = (string) Str::uuid();
        $this->push($owner, 'manual_journals', $id, $this->journal('submitted'));

        // Arriving before the journal's approval: held, not rejected.
        [$ledgerId, $bundle] = $this->ledgerBundle($id);
        $early = $this->push($owner, 'journal_postings', $ledgerId, $bundle);
        $this->assertEmpty($early->json('errors'));
        $this->assertCount(1, $early->json('deferred'));
        $this->assertNull(JournalHeader::find($ledgerId));

        // The approval lands and the held ledger journal posts by itself.
        $this->push($owner, 'manual_journals', $id, $this->journal('posted', extra: ['updated_at' => now()->addMinute()->toIso8601String()]));
        $this->assertSame('posted', JournalHeader::findOrFail($ledgerId)->status);
    }

    public function test_a_rejected_journal_never_posts_its_ledger_journal(): void
    {
        $owner = $this->tokenFor('business_owner');
        $id = (string) Str::uuid();
        $this->push($owner, 'manual_journals', $id, $this->journal('rejected'));

        [$ledgerId, $bundle] = $this->ledgerBundle($id);
        $this->assertSame('forbidden', $this->push($owner, 'journal_postings', $ledgerId, $bundle)->json('errors.0.code'));
    }

    public function test_only_a_draft_can_be_deleted(): void
    {
        $owner = $this->tokenFor('business_owner');
        $draft = (string) Str::uuid();
        $posted = (string) Str::uuid();
        $this->push($owner, 'manual_journals', $draft, $this->journal('draft'));
        $this->push($owner, 'manual_journals', $posted, $this->journal('posted'));

        $this->push($owner, 'manual_journals', $draft, ['business_id' => self::BIZ], 'delete');
        $this->push($owner, 'manual_journals', $posted, ['business_id' => self::BIZ], 'delete');

        $this->assertNull(ManualJournal::find($draft));
        $this->assertNotNull(ManualJournal::find($posted));
    }

    public function test_attachments_and_templates_sync_and_files_are_kept(): void
    {
        Storage::fake('local');
        $owner = $this->tokenFor('business_owner');
        $journalId = (string) Str::uuid();
        $this->push($owner, 'manual_journals', $journalId, $this->journal('draft'));
        $attachmentId = (string) Str::uuid();
        $this->push($owner, 'journal_attachments', $attachmentId, [
            'business_id' => self::BIZ, 'manual_journal_id' => $journalId, 'file_name' => 'invoice.pdf', 'size_bytes' => 10,
        ]);
        $templateId = (string) Str::uuid();
        $this->push($owner, 'journal_templates', $templateId, [
            'business_id' => self::BIZ, 'name' => 'Rent', 'journal_type' => 'general',
            'lines' => [['line_type' => 'gl', 'gl_account_id' => $this->account('6000')]],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$owner)
            ->post("/api/v1/journal-attachments/{$attachmentId}/file", ['file' => UploadedFile::fake()->create('invoice.pdf', 5, 'application/pdf')])
            ->assertOk();

        $path = JournalAttachment::findOrFail($attachmentId)->file_path;
        Storage::disk('local')->assertExists($path);
        $this->assertSame('Rent', JournalTemplate::findOrFail($templateId)->name);
    }
}
