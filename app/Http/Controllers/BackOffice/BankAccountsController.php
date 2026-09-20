<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Services\Accounting\AccountActivityService;
use App\Services\Accounting\BankAccountService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web-side management of a business's named bank accounts — reuses
 * MANAGE_TILLS the same way CashVaultController does, rather than adding a
 * brand new permission for a single related page. See BankAccountService
 * for the actual provisioning logic (idempotent GL account per bank
 * account); this controller is a thin list/create/deactivate wrapper plus
 * one account's own "cash book" view.
 */
class BankAccountsController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly BankAccountService $bankAccounts,
        private readonly AccountActivityService $activityService,
    ) {}

    public function index(): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $accounts = BankAccount::where('business_id', $tenantId)
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        // Assets accounts not already claimed by another active bank
        // account — what "link to an existing account" can legitimately
        // offer, same validation store() itself enforces server-side.
        $claimedGlAccountIds = BankAccount::where('business_id', $tenantId)
            ->where('is_active', true)
            ->pluck('gl_account_id');
        $linkableAccounts = GlAccount::where('business_id', $tenantId)
            ->whereHas('category', fn ($q) => $q->where('name', 'Assets'))
            ->whereNotIn('id', $claimedGlAccountIds)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('BackOffice/BankAccounts', [
            'accounts' => $accounts,
            'linkableAccounts' => $linkableAccounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'branch_code' => ['nullable', 'string', 'max:255'],
            'swift_code' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'max:10'],
            'gl_account_id' => ['nullable', 'uuid'],
        ]);

        $glAccountId = null;
        if (! empty($data['gl_account_id'])) {
            $tenantId = $this->tenantId();
            $glAccount = GlAccount::where('business_id', $tenantId)
                ->where('id', $data['gl_account_id'])
                ->whereHas('category', fn ($q) => $q->where('name', 'Assets'))
                ->firstOrFail();

            $alreadyClaimed = BankAccount::where('business_id', $tenantId)
                ->where('gl_account_id', $glAccount->id)
                ->where('is_active', true)
                ->exists();
            abort_if($alreadyClaimed, 422, 'That account already backs another active bank account.');

            $glAccountId = $glAccount->id;
        }

        $this->bankAccounts->create(
            $this->tenantId(),
            $data['name'],
            $data['account_number'] ?? null,
            $data['branch'] ?? null,
            $data['branch_code'] ?? null,
            $data['swift_code'] ?? null,
            $data['currency_code'] ?? 'USD',
            $glAccountId,
        );

        return back()->with('success', 'Bank account added.');
    }

    public function deactivate(BankAccount $bankAccount): RedirectResponse
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $this->bankAccounts->deactivate($bankAccount);

        return back()->with('success', 'Bank account deactivated.');
    }

    public function cashBook(BankAccount $bankAccount): Response
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $glAccount = GlAccount::findOrFail($bankAccount->gl_account_id);

        return Inertia::render('BackOffice/BankAccountCashBook', [
            'account' => $bankAccount,
            'balance' => $glAccount->balance(),
            'activity' => $this->activityService->activity($this->tenantId(), $glAccount),
        ]);
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_TILLS),
            403,
            'Access denied.'
        );
    }
}
