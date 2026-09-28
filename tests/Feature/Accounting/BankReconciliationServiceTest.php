<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\SyncRecord;
use App\Models\Tenant;
use App\Services\Accounting\BankAccountService;
use App\Services\Accounting\BankReconciliationService;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class BankReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeLiveBusiness(string $id = 'biz-1'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        Business::create(['id' => $id, 'name' => $id, 'currency_code' => 'USD', 'accounting_go_live_date' => '2026-01-01']);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    private function makeBankAccount(string $businessId): BankAccount
    {
        return app(BankAccountService::class)->create($businessId, 'CBZ Main Account');
    }

    /** Posts a simple Dr <bank account> / Cr Revenue (4000) journal for $amount on $date. */
    private function postDeposit(string $businessId, GlAccount $bankGl, float $amount, string $date): void
    {
        $journals = app(JournalService::class);
        $revenue = GlAccount::where('business_id', $businessId)->where('code', '4000')->firstOrFail();
        $header = $journals->createDraft($businessId, $date, 'test_deposit', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $bankGl->id, 'debit' => $amount]);
        $journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => $amount]);
        $journals->post($header);
    }

    public function test_starting_a_reconciliation_creates_an_in_progress_session(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);

        $reconciliation = app(BankReconciliationService::class)->startOrResume(
            $businessId, $bankAccount->id, '2026-06-30', 500.0, 'user-1',
        );

        $this->assertSame('in_progress', $reconciliation->status);
        $this->assertSame($bankAccount->id, $reconciliation->bank_account_id);
        $this->assertSame(500.0, (float) $reconciliation->statement_balance);
    }

    public function test_resuming_returns_the_same_in_progress_session_instead_of_creating_a_second_one(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $service = app(BankReconciliationService::class);

        $first = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 500.0, 'user-1');
        $second = $service->startOrResume($businessId, $bankAccount->id, '2026-07-31', 999.0, 'user-1');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(500.0, (float) $second->statement_balance, 'the original session is untouched, not overwritten');
    }

    public function test_toggling_a_line_marks_it_reconciled_and_republishes_every_original_field(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');

        $entry = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)->firstOrFail();
        $originalDebit = (float) $entry->debit;
        $originalDescription = $entry->description;

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');
        $service->toggleLine($reconciliation, $bankGl, $entry->id, true);

        $entry->refresh();
        $this->assertNotNull($entry->reconciled_at);
        $this->assertSame($reconciliation->id, $entry->bank_reconciliation_id);
        // The footgun regression pin: nothing else about the row changed.
        $this->assertSame($originalDebit, (float) $entry->debit);
        $this->assertSame($originalDescription, $entry->description);

        $republished = SyncRecord::where('table_name', 'general_ledger')
            ->where('record_uuid', $entry->id)
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($republished);
        $this->assertSame(100.0, (float) $republished->payload['debit']);
        $this->assertNotNull($republished->payload['reconciled_at']);
        $this->assertSame($reconciliation->id, $republished->payload['bank_reconciliation_id']);
    }

    public function test_untoggling_a_line_clears_its_reconciliation_tag(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');
        $entry = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)->firstOrFail();

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');
        $service->toggleLine($reconciliation, $bankGl, $entry->id, true);
        $service->toggleLine($reconciliation, $bankGl, $entry->id, false);

        $entry->refresh();
        $this->assertNull($entry->reconciled_at);
        $this->assertNull($entry->bank_reconciliation_id);
    }

    public function test_toggling_is_blocked_once_the_session_is_completed(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');
        $entry = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)->firstOrFail();

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');
        $service->toggleLine($reconciliation, $bankGl, $entry->id, true);
        $service->complete($reconciliation, $bankGl, 'user-1');

        $this->expectException(\RuntimeException::class);
        $service->toggleLine($reconciliation->fresh(), $bankGl, $entry->id, false);
    }

    public function test_toggling_an_entry_from_a_different_gl_account_is_rejected(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $cash = GlAccount::where('business_id', $businessId)->where('code', '1000')->firstOrFail();

        $journals = app(JournalService::class);
        $header = $journals->createDraft($businessId, '2026-06-15', 'test', (string) Str::uuid());
        $journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $journals->addLine($header, ['gl_account_id' => GlAccount::where('business_id', $businessId)->where('code', '4000')->firstOrFail()->id, 'credit' => 50]);
        $journals->post($header);
        $cashEntry = GeneralLedgerEntry::where('gl_account_id', $cash->id)->firstOrFail();

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 0.0, 'user-1');

        $this->expectException(\RuntimeException::class);
        $service->toggleLine($reconciliation, $bankGl, $cashEntry->id, true);
    }

    public function test_cleared_balance_only_counts_reconciled_lines_up_to_the_statement_date(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');
        $this->postDeposit($businessId, $bankGl, 50.0, '2026-07-05'); // after the statement date

        $entryJune = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)
            ->where('debit', 100.0)->firstOrFail();

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');
        $service->toggleLine($reconciliation, $bankGl, $entryJune->id, true);

        $this->assertSame(100.0, $service->clearedBalance($bankGl, '2026-06-30'));
    }

    public function test_complete_succeeds_when_cleared_balance_matches_the_statement_balance(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');
        $entry = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)->firstOrFail();

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');
        $service->toggleLine($reconciliation, $bankGl, $entry->id, true);
        $completed = $service->complete($reconciliation, $bankGl, 'user-1');

        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame('user-1', $completed->completed_by_user_id);
    }

    public function test_complete_fails_when_cleared_balance_does_not_match_the_statement_balance(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 999.0, 'user-1');

        $this->expectException(\RuntimeException::class);
        $service->complete($reconciliation, $bankGl, 'user-1');
    }

    public function test_cancelling_an_in_progress_session_frees_its_lines_back_into_the_unreconciled_pool(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');
        $entry = GeneralLedgerEntry::where('gl_account_id', $bankGl->id)->firstOrFail();

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');
        $service->toggleLine($reconciliation, $bankGl, $entry->id, true);
        $cancelled = $service->cancel($reconciliation);

        $this->assertSame('cancelled', $cancelled->status);
        $entry->refresh();
        $this->assertNull($entry->reconciled_at);
        $this->assertNull($entry->bank_reconciliation_id);

        // A fresh session can now be started for the same account.
        $next = $service->startOrResume($businessId, $bankAccount->id, '2026-07-31', 100.0, 'user-1');
        $this->assertNotSame($reconciliation->id, $next->id);
    }

    public function test_unreconciled_lines_excludes_already_reconciled_and_future_dated_entries(): void
    {
        $businessId = $this->makeLiveBusiness();
        $bankAccount = $this->makeBankAccount($businessId);
        $bankGl = GlAccount::find($bankAccount->gl_account_id);
        $this->postDeposit($businessId, $bankGl, 100.0, '2026-06-15');
        $this->postDeposit($businessId, $bankGl, 50.0, '2026-07-05');

        $service = app(BankReconciliationService::class);
        $reconciliation = $service->startOrResume($businessId, $bankAccount->id, '2026-06-30', 100.0, 'user-1');

        $lines = $service->unreconciledLines($bankGl, $reconciliation);
        $this->assertCount(1, $lines, 'only the June line is on/before the statement date');
        $this->assertSame(100.0, $lines[0]['debit']);
    }
}
