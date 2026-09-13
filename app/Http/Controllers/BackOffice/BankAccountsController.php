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

        return Inertia::render('BackOffice/BankAccounts', [
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:255'],
            'branch' => ['nullable', 'string', 'max:255'],
            'currency_code' => ['nullable', 'string', 'max:10'],
        ]);

        $this->bankAccounts->create(
            $this->tenantId(),
            $data['name'],
            $data['account_number'] ?? null,
            $data['branch'] ?? null,
            $data['currency_code'] ?? 'USD',
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
