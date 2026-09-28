<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\SyncRecord;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Creates a business's own named bank accounts, each backed by its own
 * `GlAccount` under a new "Bank Accounts" sub-category of Assets — codes
 * 1011-1099, reserved just above the single generic "Bank" (1010) account
 * every posting service already knows about. Existing postings that don't
 * specify a bank account keep using 1010 unchanged; this is purely
 * additive.
 */
class BankAccountService
{
    private const CODE_RANGE_START = 1011;

    private const CODE_RANGE_END = 1099;

    public function __construct(private readonly ChartOfAccountsSeeder $chartSeeder) {}

    /**
     * @param  string|null  $glAccountId  Link to this already-existing GL
     *                                    account instead of minting a new
     *                                    one — e.g. when a business already
     *                                    created the account by hand via
     *                                    Chart of Accounts management and
     *                                    just wants a bank account to point
     *                                    at it. Omitted (the default), this
     *                                    behaves exactly as before: a fresh
     *                                    account is minted under the
     *                                    reserved 1011-1099 range. Not
     *                                    validated to still be category
     *                                    Assets or unclaimed by another bank
     *                                    account here — see
     *                                    ChartOfAccountsController's own
     *                                    validation for the BackOffice path;
     *                                    this method mirrors the same "any
     *                                    id caller already resolved" trust
     *                                    level `create()`'s $glAccount
     *                                    lookup itself always had.
     */
    public function create(
        string $businessId,
        string $name,
        ?string $accountNumber = null,
        ?string $branch = null,
        ?string $branchCode = null,
        ?string $swiftCode = null,
        string $currencyCode = 'USD',
        ?string $glAccountId = null,
    ): BankAccount {
        return DB::transaction(function () use ($businessId, $name, $accountNumber, $branch, $branchCode, $swiftCode, $currencyCode, $glAccountId) {
            if ($glAccountId === null) {
                $code = $this->nextCode($businessId);
                $glAccount = $this->chartSeeder->ensureAccount(
                    $businessId,
                    'Assets',
                    'Bank Accounts',
                    ['code' => $code, 'name' => $name],
                );
                $glAccountId = $glAccount->id;
            }

            $bankAccount = BankAccount::create([
                'business_id' => $businessId,
                'name' => $name,
                'account_number' => $accountNumber,
                'branch' => $branch,
                'branch_code' => $branchCode,
                'swift_code' => $swiftCode,
                'currency_code' => $currencyCode,
                'gl_account_id' => $glAccountId,
            ]);

            $this->publish($bankAccount);

            return $bankAccount;
        });
    }

    public function deactivate(BankAccount $bankAccount): void
    {
        $bankAccount->update(['is_active' => false]);
        $this->publish($bankAccount->fresh());
    }

    /**
     * Atomic per-business sequence within the reserved range — same
     * locked-max()+1 pattern as JournalService::nextJournalNumber() and
     * GrvPostingService::nextGrvNumber(), so two devices creating a bank
     * account for the same business offline can't collide on a code.
     */
    private function nextCode(string $businessId): string
    {
        $codes = GlAccount::where('business_id', $businessId)
            ->whereBetween('code', [(string) self::CODE_RANGE_START, (string) self::CODE_RANGE_END])
            ->lockForUpdate()
            ->pluck('code');

        $max = $codes->map(fn (string $c) => (int) $c)->max() ?? (self::CODE_RANGE_START - 1);
        $next = max(self::CODE_RANGE_START, $max + 1);

        if ($next > self::CODE_RANGE_END) {
            throw new RuntimeException('Too many bank accounts — the reserved chart-of-accounts range is exhausted.');
        }

        return (string) $next;
    }

    private function publish(BankAccount $bankAccount): void
    {
        SyncRecord::create([
            'business_id' => $bankAccount->business_id,
            'table_name' => 'bank_accounts',
            'record_uuid' => $bankAccount->id,
            'operation' => 'upsert',
            'payload' => [
                'business_id' => $bankAccount->business_id,
                'name' => $bankAccount->name,
                'account_number' => $bankAccount->account_number,
                'branch' => $bankAccount->branch,
                'branch_code' => $bankAccount->branch_code,
                'swift_code' => $bankAccount->swift_code,
                'currency_code' => $bankAccount->currency_code,
                'gl_account_id' => $bankAccount->gl_account_id,
                'is_active' => $bankAccount->is_active,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
