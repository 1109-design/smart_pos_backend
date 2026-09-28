<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\GlAccount;
use App\Models\Accounting\JournalHeader;
use App\Models\Accounting\JournalLine;
use App\Models\BankAccount;
use App\Models\Business;
use App\Models\Expense;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts an expense to the general ledger: Dr the GL account under the
 * "Expenses" category whose name matches the expense's category (e.g.
 * 'Rent' -> GL 6000 'Rent'), falling back to the catch-all '6090 General
 * Expenses' account for any category with no seeded line of its own
 * (most of the app's 16 categories don't, today — a deliberate,
 * minimal-scope choice rather than a new category->GL-account mapping
 * mechanism) / Cr whichever cash/bank/mobile-money role the payment method
 * implies (or a specifically tagged BankAccount's own GL line for card).
 * Mirrors SalaryPostingService's shape.
 *
 * Unlike every sibling posting service, an expense can genuinely be edited
 * or cancelled after it's already posted (see expenses_screen.dart), so
 * postIfReady() doesn't just guard against re-posting the same row twice —
 * it reverses a stale posting when the expense's numbers changed, or when
 * it's been cancelled since it was last posted, via JournalService::reverse()
 * (already used for voided sales).
 */
class ExpensePostingService
{
    /**
     * See the Dart twin's identical constant/doc comment — the payroll
     * flow's own record-keeping Expense row is posted via
     * SalaryPostingService against the SalaryPayments row instead; posting
     * it here too would double-count the same payment. That flow tags its
     * row with the literal category 'Salary' (singular), which the
     * category picker itself never offers (it only offers 'Salaries',
     * plural) — a reliable signal this row isn't ours to post.
     */
    private const PAYROLL_GENERATED_CATEGORY = 'Salary';

    public function __construct(
        private readonly JournalService $journals,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    /**
     * @param  bool  $viaSweep  True only from a pending-expenses sweep's
     *                          grace-period fallback for a
     *                          client_gl_posting_enabled_at business — see
     *                          SalePostingService::postIfReady()'s identical
     *                          parameter.
     */
    public function postIfReady(Expense $expense, bool $viaSweep = false): void
    {
        if ($expense->category === self::PAYROLL_GENERATED_CATEGORY) {
            return;
        }

        $business = Business::find($expense->business_id);
        if (! $business?->accountingIsLive()) {
            return;
        }

        $transDate = $expense->expense_date?->toDateString() ?? now()->toDateString();
        if ($transDate < $business->accounting_go_live_date->toDateString()) {
            return;
        }

        if (! $viaSweep && $business->postsFromClientFor($transDate)) {
            return;
        }

        $existing = JournalHeader::where('source_type', 'expense')
            ->where('source_id', $expense->id)
            ->where('status', 'posted')
            ->first();

        try {
            if ($expense->deleted_at !== null) {
                if ($existing) {
                    $this->journals->reverse($existing, null, 'Expense cancelled');
                }

                return;
            }

            $amount = round((float) $expense->base_equivalent, 4);
            if ($amount <= 0.005) {
                if ($existing) {
                    $this->journals->reverse($existing, null, 'Expense amount cleared');
                }

                return;
            }

            $expenseAccount = $this->resolveExpenseAccount($expense->business_id, $expense->category);
            if (! $expenseAccount) {
                return; // chart of accounts not seeded yet
            }
            $fundingAccount = $this->resolveFundingAccount($expense->business_id, $expense->payment_method, $expense->bank_account_id);

            if ($existing) {
                if ($this->postingMatches($existing, $expenseAccount->id, $fundingAccount->id, $amount)) {
                    return; // idempotency guard — nothing actually changed
                }
                $this->journals->reverse($existing, null, 'Expense edited');
            }

            $header = $this->journals->createDraft(
                $expense->business_id,
                $transDate,
                'expense',
                $expense->id,
                'Expense — '.$expense->category,
            );
            $this->journals->addLine($header, ['gl_account_id' => $expenseAccount->id, 'debit' => $amount]);
            $this->journals->addLine($header, ['gl_account_id' => $fundingAccount->id, 'credit' => $amount]);
            $this->journals->post($header);
        } catch (Throwable $e) {
            Log::warning("Accounting: failed to post expense {$expense->id}: {$e->getMessage()}");
        }
    }

    private function postingMatches(JournalHeader $header, string $expenseAccountId, string $fundingAccountId, float $amount): bool
    {
        $lines = $header->lines()->get();
        if ($lines->count() !== 2) {
            return false;
        }
        $debitLine = $lines->first(fn (JournalLine $l) => (float) $l->debit > 0);
        $creditLine = $lines->first(fn (JournalLine $l) => (float) $l->credit > 0);
        if (! $debitLine || ! $creditLine) {
            return false;
        }

        return $debitLine->gl_account_id === $expenseAccountId
            && $creditLine->gl_account_id === $fundingAccountId
            && abs((float) $debitLine->debit - $amount) < 0.005;
    }

    private function resolveExpenseAccount(string $businessId, string $category): ?GlAccount
    {
        $expensesCategory = AccountCategory::where('business_id', $businessId)->where('name', 'Expenses')->first();
        if (! $expensesCategory) {
            return null;
        }

        $accounts = GlAccount::where('account_category_id', $expensesCategory->id)->get();
        $match = $accounts->first(fn (GlAccount $a) => strtolower($a->name) === strtolower($category));

        return $match ?? $accounts->first(fn (GlAccount $a) => strtolower($a->name) === 'general expenses');
    }

    private function resolveFundingAccount(string $businessId, string $method, ?string $bankAccountId): GlAccount
    {
        $m = strtolower($method);
        $role = match (true) {
            str_contains($m, 'mobile') => 'default_mobile_money',
            str_contains($m, 'card'), str_contains($m, 'bank'), str_contains($m, 'swipe') => 'default_bank',
            default => 'default_cash',
        };

        $account = $this->mappings->resolve($businessId, $role);

        return $role === 'default_bank' ? $this->resolveBankAccount($bankAccountId, $account) : $account;
    }

    /**
     * See SalePostingService::resolveBankAccount() — identical fallback
     * behavior.
     */
    private function resolveBankAccount(?string $bankAccountId, GlAccount $default): GlAccount
    {
        if (! $bankAccountId) {
            return $default;
        }

        $bankAccount = BankAccount::find($bankAccountId);
        $glAccount = $bankAccount ? GlAccount::find($bankAccount->gl_account_id) : null;

        return $glAccount ?? $default;
    }
}
