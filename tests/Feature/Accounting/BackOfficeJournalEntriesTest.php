<?php

namespace Tests\Feature\Accounting;

use App\Http\Middleware\AuthenticateBackOfficeUser;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Business;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackOfficeJournalEntriesTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId = 'tenant-je-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(AuthenticateBackOfficeUser::class);

        Tenant::create(['id' => $this->tenantId, 'business_name' => $this->tenantId, 'owner_email' => 'a@example.com', 'pairing_code' => substr(md5($this->tenantId), 0, 6)]);
        Business::create(['id' => $this->tenantId, 'name' => $this->tenantId, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($this->tenantId);
    }

    private function account(string $code): GlAccount
    {
        return GlAccount::where('business_id', $this->tenantId)->where('code', $code)->firstOrFail();
    }

    private function fundCash(float $amount): void
    {
        $journals = app(JournalService::class);
        $header = $journals->createDraft($this->tenantId, '2026-05-01', 'capital', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $this->account('1000')->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $this->account('3000')->id, 'credit' => $amount]);
        $journals->post($header);
    }

    private function actingBackOfficeAs(string $role): void
    {
        $user = User::factory()->create(['id' => (string) Str::uuid(), 'business_id' => $this->tenantId, 'is_active' => true]);

        session(['backoffice' => [
            'tenant_id' => $this->tenantId, 'user_id' => $user->id, 'user_name' => $user->name,
            'user_email' => $user->email, 'role' => $role, 'business_name' => $this->tenantId,
            'currency_code' => 'USD',
        ]]);
    }

    public function test_owner_can_post_a_balanced_manual_journal_entry(): void
    {
        $this->actingBackOfficeAs('business_owner');
        $this->fundCash(1000);

        $response = $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Opening rent accrual',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 150, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 150],
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(850.0, $this->account('1000')->balance());
        $this->assertSame(150.0, $this->account('6000')->balance());
        $this->assertDatabaseHas('journal_headers', ['business_id' => $this->tenantId, 'source_type' => 'manual', 'status' => 'posted']);
    }

    public function test_an_unbalanced_entry_is_rejected(): void
    {
        $this->actingBackOfficeAs('business_owner');

        $response = $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Bad entry',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 150, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 100],
            ],
        ]);

        $response->assertSessionHasErrors('journal');
        $this->assertSame(0.0, $this->account('6000')->balance());
    }

    public function test_a_control_account_cannot_be_posted_to_from_a_manual_entry(): void
    {
        $this->actingBackOfficeAs('business_owner');

        $response = $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Should be rejected',
            'lines' => [
                ['gl_account_id' => $this->account('1100')->id, 'debit' => 100, 'credit' => 0], // Accounts Receivable
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 100],
            ],
        ]);

        $response->assertSessionHasErrors('journal');
        $this->assertSame(0.0, $this->account('1100')->balance());
    }

    public function test_manager_cannot_post_journal_entries_by_default(): void
    {
        $this->actingBackOfficeAs('manager');

        $this->get('/office/journal-entries')->assertForbidden();
        $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Nope',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 10, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 10],
            ],
        ])->assertForbidden();
    }

    public function test_owner_can_reverse_a_posted_manual_entry(): void
    {
        $this->actingBackOfficeAs('business_owner');
        $this->fundCash(1000);

        $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'To be reversed',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 150, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 150],
            ],
        ]);

        $original = JournalHeader::where('business_id', $this->tenantId)->where('source_type', 'manual')->firstOrFail();

        $response = $this->post("/office/journal-entries/{$original->id}/reverse", ['reason' => 'Entered in error']);

        $response->assertRedirect();
        $this->assertSame(0.0, $this->account('6000')->balance());
        $this->assertSame(1000.0, $this->account('1000')->balance());
        $this->assertDatabaseHas('journal_headers', ['id' => $original->id, 'status' => 'reversed']);
    }

    public function test_index_lists_manual_and_reversal_journals_with_their_lines(): void
    {
        $this->actingBackOfficeAs('business_owner');
        $this->fundCash(1000);

        $this->post('/office/journal-entries', [
            'trans_date' => '2026-06-01',
            'description' => 'Listed entry',
            'lines' => [
                ['gl_account_id' => $this->account('6000')->id, 'debit' => 20, 'credit' => 0],
                ['gl_account_id' => $this->account('1000')->id, 'debit' => 0, 'credit' => 20],
            ],
        ]);

        $response = $this->get('/office/journal-entries');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('journals.data', 1)
            ->has('journals.data.0.lines', 2)
        );
    }
}
