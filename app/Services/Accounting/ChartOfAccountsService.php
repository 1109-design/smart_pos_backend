<?php

namespace App\Services\Accounting;

use App\Models\Accounting\AccountCategory;
use App\Models\Accounting\AccountSubCategory;
use App\Models\Accounting\GlAccount;
use App\Models\AccountRoleMapping;
use App\Models\BankAccount;
use App\Models\SyncRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * BackOffice/Flutter-facing CRUD over the chart of accounts —
 * `ChartOfAccountsSeeder` only ever seeds the starting set once; this is
 * what actually lets an owner customize it afterward, closing the gap its
 * own doc comment ("An owner can rename or add accounts afterward") never
 * had a real implementation for.
 *
 * Deliberately never accepts an account's `code` on update — several
 * posting services resolve control accounts by hardcoded code string
 * (OpeningBalanceService, GrvPostingService, SupplierInvoiceService,
 * StockTakePostingService, ProductOpeningStockPostingService), so renaming
 * an existing code would silently break those lookups rather than error
 * loudly. A new account's code is always auto-assigned within its
 * category's own reserved "user accounts" sub-range (category code + 900
 * to +999 — clear of both the seeded accounts, which stay under +600, and
 * BankAccountService's own reserved 1011-1099), the same locked-max()+1
 * pattern that file already uses for bank-account codes.
 *
 * Writes go straight through Eloquent + a hand-written SyncRecord, the same
 * pattern BankAccountService/ChartOfAccountsSeeder/ApprovalService already
 * use for a BackOffice-authored write — never through SyncProcessor, so the
 * untrusted-push gate added alongside this class only ever applies to a
 * device's own pushes, never to this service's own writes.
 */
class ChartOfAccountsService
{
    private const USER_ACCOUNT_CODE_OFFSET_START = 900;

    private const USER_ACCOUNT_CODE_OFFSET_END = 999;

    public function createCategory(string $businessId, string $name, bool $isDebitNormal, string $statementType): AccountCategory
    {
        return DB::transaction(function () use ($businessId, $name, $isDebitNormal, $statementType) {
            $code = $this->nextCategoryCode($businessId);

            $category = AccountCategory::create([
                'business_id' => $businessId,
                'name' => $name,
                'code' => $code,
                'is_debit_normal' => $isDebitNormal,
                'statement_type' => $statementType,
                'reporting_order' => $code,
                'is_system' => false,
            ]);

            $this->publishCategory($category);

            return $category;
        });
    }

    public function renameCategory(AccountCategory $category, string $name): void
    {
        $category->update(['name' => $name]);
        $this->publishCategory($category->fresh());
    }

    public function reorderCategory(AccountCategory $category, int $reportingOrder): void
    {
        $category->update(['reporting_order' => $reportingOrder]);
        $this->publishCategory($category->fresh());
    }

    public function createSubCategory(string $businessId, AccountCategory $category, string $name): AccountSubCategory
    {
        return DB::transaction(function () use ($businessId, $category, $name) {
            $nextOrder = (int) (AccountSubCategory::where('account_category_id', $category->id)
                ->lockForUpdate()
                ->max('reporting_order') ?? -1) + 1;

            $subCategory = AccountSubCategory::create([
                'business_id' => $businessId,
                'account_category_id' => $category->id,
                'name' => $name,
                'reporting_order' => $nextOrder,
            ]);

            $this->publishSubCategory($subCategory);

            return $subCategory;
        });
    }

    public function renameSubCategory(AccountSubCategory $subCategory, string $name): void
    {
        $subCategory->update(['name' => $name]);
        $this->publishSubCategory($subCategory->fresh());
    }

    public function reorderSubCategory(AccountSubCategory $subCategory, int $reportingOrder): void
    {
        $subCategory->update(['reporting_order' => $reportingOrder]);
        $this->publishSubCategory($subCategory->fresh());
    }

    /**
     * @param  'receivable'|'payable'|'inventory'|null  $controlType
     */
    public function createAccount(
        string $businessId,
        AccountCategory $category,
        ?AccountSubCategory $subCategory,
        string $name,
        ?string $controlType = null,
        bool $allowDirectPosting = true,
        bool $mustBePositive = false,
    ): GlAccount {
        return DB::transaction(function () use ($businessId, $category, $subCategory, $name, $controlType, $allowDirectPosting, $mustBePositive) {
            $code = $this->nextAccountCode($businessId, $category);

            $account = GlAccount::create([
                'business_id' => $businessId,
                'code' => $code,
                'name' => $name,
                'account_category_id' => $category->id,
                'account_sub_category_id' => $subCategory?->id,
                'allow_direct_posting' => $allowDirectPosting,
                'control_type' => $controlType,
                'must_be_positive' => $mustBePositive,
                'status' => 'active',
            ]);

            $this->publishAccount($account);

            return $account;
        });
    }

    /**
     * Rename and/or reassign category/sub-category and posting flags —
     * never the code (see class doc comment).
     */
    public function updateAccount(
        GlAccount $account,
        string $name,
        AccountCategory $category,
        ?AccountSubCategory $subCategory,
        ?string $controlType,
        bool $allowDirectPosting,
        bool $mustBePositive,
    ): void {
        $account->update([
            'name' => $name,
            'account_category_id' => $category->id,
            'account_sub_category_id' => $subCategory?->id,
            'control_type' => $controlType,
            'allow_direct_posting' => $allowDirectPosting,
            'must_be_positive' => $mustBePositive,
        ]);

        $this->publishAccount($account->fresh());
    }

    /**
     * Blocks deactivating an account that still has a live balance, or that
     * something else currently depends on to route real money — an
     * AccountRoleMapping or an active BankAccount — since deactivating out
     * from under either would silently strand future postings rather than
     * error loudly at the point that actually matters.
     */
    public function deactivateAccount(GlAccount $account): void
    {
        $balance = $account->balance();
        if (abs($balance) > 0.005) {
            throw new RuntimeException(
                "Cannot deactivate {$account->code} — {$account->name}: it still has a balance of ".number_format($balance, 2).'. Move or clear it first.'
            );
        }

        $mapping = AccountRoleMapping::where('gl_account_id', $account->id)->first();
        if ($mapping) {
            throw new RuntimeException(
                "Cannot deactivate {$account->code} — {$account->name}: it's currently mapped to the '{$mapping->role}' posting role. Reassign that mapping first."
            );
        }

        $bankAccount = BankAccount::where('gl_account_id', $account->id)->where('is_active', true)->first();
        if ($bankAccount) {
            throw new RuntimeException(
                "Cannot deactivate {$account->code} — {$account->name}: bank account '{$bankAccount->name}' still posts to it. Deactivate that bank account first."
            );
        }

        $account->update(['status' => 'inactive']);
        $this->publishAccount($account->fresh());
    }

    public function reactivateAccount(GlAccount $account): void
    {
        $account->update(['status' => 'active']);
        $this->publishAccount($account->fresh());
    }

    /**
     * Atomic per-business, per-category sequence within that category's
     * reserved "user accounts" sub-range — same locked-max()+1 pattern as
     * BankAccountService::nextCode(), generalized from one hardcoded range
     * to any category.
     */
    private function nextAccountCode(string $businessId, AccountCategory $category): string
    {
        $base = (int) ($category->code ?? 0);
        $rangeStart = $base + self::USER_ACCOUNT_CODE_OFFSET_START;
        $rangeEnd = $base + self::USER_ACCOUNT_CODE_OFFSET_END;

        $codes = GlAccount::where('business_id', $businessId)
            ->whereBetween('code', [(string) $rangeStart, (string) $rangeEnd])
            ->lockForUpdate()
            ->pluck('code');

        $max = $codes->map(fn (string $c) => (int) $c)->max() ?? ($rangeStart - 1);
        $next = max($rangeStart, $max + 1);

        if ($next > $rangeEnd) {
            throw new RuntimeException("Too many accounts in {$category->name} — its reserved chart-of-accounts range is exhausted.");
        }

        return (string) $next;
    }

    private function nextCategoryCode(string $businessId): int
    {
        $codes = AccountCategory::where('business_id', $businessId)
            ->lockForUpdate()
            ->pluck('code')
            ->filter()
            ->map(fn ($c) => (int) $c);

        $maxBlock = (int) (floor(($codes->max() ?? 6000) / 1000)) * 1000;

        return max(7000, $maxBlock + 1000);
    }

    private function publishCategory(AccountCategory $category): void
    {
        $this->publish('account_categories', $category->business_id, $category->id, [
            'id' => $category->id,
            'business_id' => $category->business_id,
            'name' => $category->name,
            'code' => $category->code,
            'is_debit_normal' => $category->is_debit_normal,
            'statement_type' => $category->statement_type,
            'reporting_order' => $category->reporting_order,
            'is_system' => $category->is_system,
        ]);
    }

    private function publishSubCategory(AccountSubCategory $subCategory): void
    {
        $this->publish('account_sub_categories', $subCategory->business_id, $subCategory->id, [
            'id' => $subCategory->id,
            'business_id' => $subCategory->business_id,
            'account_category_id' => $subCategory->account_category_id,
            'name' => $subCategory->name,
            'reporting_order' => $subCategory->reporting_order,
        ]);
    }

    private function publishAccount(GlAccount $account): void
    {
        $this->publish('gl_accounts', $account->business_id, $account->id, [
            'id' => $account->id,
            'business_id' => $account->business_id,
            'code' => $account->code,
            'name' => $account->name,
            'account_category_id' => $account->account_category_id,
            'account_sub_category_id' => $account->account_sub_category_id,
            'allow_direct_posting' => $account->allow_direct_posting,
            'control_type' => $account->control_type,
            'must_be_positive' => $account->must_be_positive,
            'status' => $account->status,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publish(string $table, string $businessId, string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $businessId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
