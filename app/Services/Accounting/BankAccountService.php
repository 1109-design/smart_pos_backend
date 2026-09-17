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

    public function create(
        string $businessId,
        string $name,
        ?string $accountNumber = null,
        ?string $branch = null,
        string $currencyCode = 'USD',
    ): BankAccount {
        return DB::transaction(function () use ($businessId, $name, $accountNumber, $branch, $currencyCode) {
            $code = $this->nextCode($businessId);
            $glAccount = $this->chartSeeder->ensureAccount(
                $businessId,
                'Assets',
                'Bank Accounts',
                ['code' => $code, 'name' => $name],
            );

            $bankAccount = BankAccount::create([
                'business_id' => $businessId,
                'name' => $name,
                'account_number' => $accountNumber,
                'branch' => $branch,
                'currency_code' => $currencyCode,
                'gl_account_id' => $glAccount->id,
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
                'currency_code' => $bankAccount->currency_code,
                'gl_account_id' => $bankAccount->gl_account_id,
                'is_active' => $bankAccount->is_active,
            ],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
