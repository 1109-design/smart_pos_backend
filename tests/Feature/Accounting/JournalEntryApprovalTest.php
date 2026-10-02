<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\ApprovalRequest;
use App\Models\ApprovalRule;
use App\Models\ApprovalRuleSet;
use App\Models\Business;
use App\Models\Requisition;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use App\Services\ApprovalService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Approval processes that previously did nothing once decided: a
 * 'journal_entry' rule now holds a manual journal as a draft until it's
 * approved; a requisition approved from BackOffice is actually approved;
 * and approvals only the app can carry out are refused in BackOffice.
 */
class JournalEntryApprovalTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-je-approval';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);
        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => 'a@example.com', 'pairing_code' => substr(md5($this->tenantId), 0, 6)]);
        Business::create(['id' => $this->tenantId, 'name' => $this->tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->tenantId);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'email' => Str::random(8).'@x.com', 'is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function actingBackOfficeAs(User $user): void
    {
        session(['backoffice' => [
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => 'business_owner', 'business_name' => $this->tenantId,
            'currency_code' => 'USD',
        ]]);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->tenantId)->where('code', $code)->firstOrFail();
    }

    private function rule(string $process): void
    {
        $set = ApprovalRuleSet::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'process' => $process, 'name' => $process, 'is_enabled' => true]);
        ApprovalRule::create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'rule_set_id' => $set->id, 'level' => 1, 'condition_type' => 'always', 'sla_hours' => 8]);
    }

    private function postJournal(): void
    {
        // Cash (1000) can't go negative — fund it first.
        $journals = app(JournalService::class);
        $capital = $journals->createDraft($this->tenantId, '2026-05-01', 'capital', (string) Str::uuid());
        $journals->addLine($capital, ['gl_account_id' => $this->account('1000')->id, 'debit' => 1000]);
        $journals->addLine($capital, ['gl_account_id' => $this->account('3000')->id, 'credit' => 1000]);
        $journals->post($capital);

        $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Rent accrual',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 150, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 150],
            ],
        ])->assertRedirect();
    }

    public function test_a_journal_entry_rule_holds_the_entry_until_approved(): void
    {
        $this->rule('journal_entry');
        $maker = $this->user('manager');
        $checker = $this->user('manager');
        $this->actingBackOfficeAs($maker);

        $this->postJournal();

        $draft = JournalHeader::where('business_id', $this->tenantId)->where('source_type', 'manual')->firstOrFail();
        $this->assertSame('draft', $draft->status);
        $this->assertSame(0.0, $this->account('6000')->balance());

        $request = ApprovalRequest::where('subject_id', $draft->id)->where('action', 'approve_journal_entry')->firstOrFail();
        app(ApprovalService::class)->resolve($request->id, $checker->id, 'approved');

        $this->assertSame('posted', $draft->fresh()->status);
        $this->assertSame(150.0, $this->account('6000')->balance());
    }

    public function test_rejecting_a_held_journal_entry_discards_the_draft(): void
    {
        $this->rule('journal_entry');
        $maker = $this->user('manager');
        $checker = $this->user('manager');
        $this->actingBackOfficeAs($maker);
        $this->postJournal();

        $draft = JournalHeader::where('business_id', $this->tenantId)->where('source_type', 'manual')->firstOrFail();
        $request = ApprovalRequest::where('subject_id', $draft->id)->firstOrFail();
        app(ApprovalService::class)->resolve($request->id, $checker->id, 'rejected', 'Wrong period');

        $this->assertNull(JournalHeader::find($draft->id));
    }

    public function test_without_a_rule_a_journal_entry_posts_immediately(): void
    {
        $this->actingBackOfficeAs($this->user('manager'));
        $this->postJournal();

        $this->assertDatabaseHas('journal_headers', ['business_id' => $this->tenantId, 'source_type' => 'manual', 'status' => 'posted']);
        $this->assertDatabaseMissing('approval_requests', ['action' => 'approve_journal_entry']);
    }

    public function test_a_requisition_approved_in_backoffice_is_approved(): void
    {
        $requester = $this->user('cashier');
        $manager = $this->user('manager');
        $requisition = Requisition::create([
            'id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'requisition_number' => 'REQ-1',
            'location_id' => (string) Str::uuid(), 'purpose' => 'general', 'status' => 'pending', 'requested_by_user_id' => $requester->id,
        ]);
        $request = app(ApprovalService::class)->request($this->tenantId, 'Requisition', $requisition->id, 'approve_requisition', $requester->id);

        app(ApprovalService::class)->resolve($request->id, $manager->id, 'approved');

        $this->assertSame('approved', $requisition->fresh()->status);
        $this->assertSame($manager->id, $requisition->fresh()->approved_by_user_id);
    }

    public function test_approvals_only_the_app_can_apply_are_refused_in_backoffice(): void
    {
        $requester = $this->user('cashier');
        $manager = $this->user('manager');
        $request = app(ApprovalService::class)->request($this->tenantId, 'till_cash_movement', (string) Str::uuid(), 'cash_vault_drop', $requester->id, ['amount' => 50]);

        $this->expectExceptionMessage('approve it from the Approvals inbox in the app');
        app(ApprovalService::class)->resolve($request->id, $manager->id, 'approved');
    }
}
