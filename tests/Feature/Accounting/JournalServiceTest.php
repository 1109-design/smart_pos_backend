<?php

namespace Tests\Feature\Accounting;

use App\Models\Accounting\AccountingPeriod;
use App\Models\Accounting\GeneralLedgerEntry;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalLine;
use App\Models\Tenant;
use App\Services\Accounting\ChartOfAccountsSeeder;
use App\Services\Accounting\JournalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class JournalServiceTest extends TestCase
{
    use RefreshDatabase;

    private JournalService $journals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->journals = app(JournalService::class);
    }

    private function makeBusiness(string $id = 'biz-1'): string
    {
        Tenant::create(['id' => $id, 'business_name' => $id, 'owner_email' => "{$id}@example.com"]);
        (new ChartOfAccountsSeeder)->seedForBusiness($id);

        return $id;
    }

    private function account(string $businessId, string $code): GlAccount
    {
        return GlAccount::where('business_id', $businessId)->where('code', $code)->firstOrFail();
    }

    public function test_a_balanced_draft_posts_and_writes_the_general_ledger(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05', 'sale', 'sale-1', 'Cash sale');
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 50]);

        $this->assertTrue($this->journals->isBalanced($header));

        $posted = $this->journals->post($header, 'user-1');

        $this->assertSame('posted', $posted->status);
        $this->assertNotNull($posted->posted_at);
        $this->assertSame('user-1', $posted->posted_by_user_id);
        $this->assertSame(2, GeneralLedgerEntry::where('journal_header_id', $posted->id)->count());
        $this->assertSame(50.0, $cash->fresh()->balance());
        $this->assertSame(50.0, $revenue->fresh()->balance());
    }

    public function test_journal_numbers_are_sequential_per_business_and_year(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $first = $this->journals->createDraft($businessId, '2026-09-05');
        $second = $this->journals->createDraft($businessId, '2026-09-05');

        $this->assertSame('JNL-2026-00001', $first->journal_number);
        $this->assertSame('JNL-2026-00002', $second->journal_number);
    }

    public function test_journal_numbers_are_independent_per_business(): void
    {
        $a = $this->makeBusiness('biz-a');
        $b = $this->makeBusiness('biz-b');

        $first = $this->journals->createDraft($a, '2026-09-05');
        $second = $this->journals->createDraft($b, '2026-09-05');

        $this->assertSame('JNL-2026-00001', $first->journal_number);
        $this->assertSame('JNL-2026-00001', $second->journal_number);
    }

    public function test_posting_an_unbalanced_journal_is_rejected(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 40]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not balance');

        $this->journals->post($header);
    }

    public function test_posting_a_journal_with_no_lines_is_rejected(): void
    {
        $businessId = $this->makeBusiness();
        $header = $this->journals->createDraft($businessId, '2026-09-05');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no lines');

        $this->journals->post($header);
    }

    public function test_a_posted_journal_cannot_be_posted_again(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 50]);
        $posted = $this->journals->post($header);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('already posted');

        $this->journals->post($posted);
    }

    public function test_lines_cannot_be_added_or_removed_once_posted(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $line1 = $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 50]);
        $posted = $this->journals->post($header);

        $this->expectException(RuntimeException::class);
        $this->journals->addLine($posted, ['gl_account_id' => $cash->id, 'debit' => 10]);
    }

    public function test_a_posted_journal_lines_row_resists_direct_deletion(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $line = $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 50]);
        $this->journals->post($header);

        $line->delete();

        $this->assertNotNull(JournalLine::find($line->id), 'a posted line must survive a direct delete() call');
    }

    public function test_posting_is_blocked_into_a_closed_accounting_period(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        AccountingPeriod::create([
            'business_id' => $businessId,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'closed',
        ]);

        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 50]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('closed');

        $this->journals->post($header);
    }

    public function test_posting_is_blocked_on_the_exact_boundary_dates_of_a_closed_period(): void
    {
        // Regression: a naive column comparison against a raw "Y-m-d"
        // string wrongly excludes a boundary date once the column carries a
        // stored time part — see AccountingPeriod::isClosedFor()'s doc
        // comment. Both ends of the range are exercised here.
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        AccountingPeriod::create([
            'business_id' => $businessId,
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'status' => 'closed',
        ]);

        foreach (['2026-09-01', '2026-09-30'] as $boundaryDate) {
            $header = $this->journals->createDraft($businessId, $boundaryDate);
            $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 10]);
            $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 10]);

            try {
                $this->journals->post($header);
                $this->fail("Expected posting on the boundary date {$boundaryDate} to be blocked.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('closed', $e->getMessage());
            }
        }
    }

    public function test_posting_into_a_date_with_no_period_record_is_allowed(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        // No AccountingPeriod row at all — absent means open, per the same
        // opt-in-gate convention as Business::workflow_settings.
        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 50]);

        $posted = $this->journals->post($header);

        $this->assertSame('posted', $posted->status);
    }

    public function test_a_must_be_positive_account_cannot_be_posted_negative(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000'); // must_be_positive = true
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05');
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'debit' => 50]);
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'credit' => 50]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('negative');

        $this->journals->post($header);

        // And the failed post must not have left a half-applied ledger.
        $this->assertSame(0, GeneralLedgerEntry::where('business_id', $businessId)->count());
    }

    public function test_reversing_a_posted_journal_swaps_every_line_and_nets_to_zero(): void
    {
        $businessId = $this->makeBusiness();
        $cash = $this->account($businessId, '1000');
        $revenue = $this->account($businessId, '4000');

        $header = $this->journals->createDraft($businessId, '2026-09-05', 'sale', 'sale-1');
        $this->journals->addLine($header, ['gl_account_id' => $cash->id, 'debit' => 75]);
        $this->journals->addLine($header, ['gl_account_id' => $revenue->id, 'credit' => 75]);
        $posted = $this->journals->post($header);

        $reversal = $this->journals->reverse($posted, 'user-2', 'refund');

        $this->assertSame('posted', $reversal->status);
        $this->assertSame('reversed', $posted->fresh()->status);
        $this->assertSame($reversal->id, $posted->fresh()->reversed_by_journal_id);
        $this->assertSame($posted->id, $reversal->reversal_of_journal_id);

        // Both accounts net back to zero once the reversal is in.
        $this->assertSame(0.0, $cash->fresh()->balance());
        $this->assertSame(0.0, $revenue->fresh()->balance());

        // The original's ledger rows are tagged, not removed or excluded.
        $this->assertSame(
            2,
            GeneralLedgerEntry::where('journal_header_id', $posted->id)->where('status', 'reversed')->count()
        );
    }

    public function test_only_a_posted_journal_can_be_reversed(): void
    {
        $businessId = $this->makeBusiness();
        $header = $this->journals->createDraft($businessId, '2026-09-05');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only a posted journal');

        $this->journals->reverse($header);
    }
}
