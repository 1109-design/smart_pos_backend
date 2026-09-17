<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Accounting\GlAccount;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Services\Accounting\BankReconciliationService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Web-side of ticking a bank account's cash book off against a real
 * statement — reuses MANAGE_TILLS the same way BankAccountsController does.
 * See BankReconciliationService for the actual tick-off/complete/cancel
 * logic; this controller is a thin wrapper.
 */
class BankReconciliationController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly BankReconciliationService $reconciliations,
    ) {}

    public function show(BankAccount $bankAccount): Response
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $reconciliation = BankReconciliation::where('bank_account_id', $bankAccount->id)
            ->where('status', 'in_progress')
            ->first();

        $glAccount = GlAccount::findOrFail($bankAccount->gl_account_id);

        return Inertia::render('BackOffice/BankReconciliation', [
            'account' => $bankAccount,
            'reconciliation' => $reconciliation,
            'unreconciledLines' => $reconciliation ? $this->reconciliations->unreconciledLines($glAccount, $reconciliation) : [],
            'clearedBalance' => $reconciliation ? $this->reconciliations->clearedBalance($glAccount, $reconciliation->statement_date->toDateString()) : null,
        ]);
    }

    public function start(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $data = $request->validate([
            'statement_date' => ['required', 'date'],
            'statement_balance' => ['required', 'numeric'],
        ]);

        $this->reconciliations->startOrResume(
            $this->tenantId(),
            $bankAccount->id,
            $data['statement_date'],
            (float) $data['statement_balance'],
            $this->userId(),
        );

        return back()->with('success', 'Reconciliation started.');
    }

    public function toggle(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $data = $request->validate([
            'entry_id' => ['required', 'string'],
            'cleared' => ['required', 'boolean'],
        ]);

        $reconciliation = BankReconciliation::where('bank_account_id', $bankAccount->id)
            ->where('status', 'in_progress')
            ->firstOrFail();
        $glAccount = GlAccount::findOrFail($bankAccount->gl_account_id);

        try {
            $this->reconciliations->toggleLine($reconciliation, $glAccount, $data['entry_id'], $data['cleared']);
        } catch (RuntimeException $e) {
            return back()->withErrors(['entry_id' => $e->getMessage()]);
        }

        return back();
    }

    public function complete(BankAccount $bankAccount): RedirectResponse
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $reconciliation = BankReconciliation::where('bank_account_id', $bankAccount->id)
            ->where('status', 'in_progress')
            ->firstOrFail();
        $glAccount = GlAccount::findOrFail($bankAccount->gl_account_id);

        try {
            $this->reconciliations->complete($reconciliation, $glAccount, $this->userId());
        } catch (RuntimeException $e) {
            return back()->withErrors(['statement_balance' => $e->getMessage()]);
        }

        return back()->with('success', 'Reconciliation completed.');
    }

    public function cancel(BankAccount $bankAccount): RedirectResponse
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $reconciliation = BankReconciliation::where('bank_account_id', $bankAccount->id)
            ->where('status', 'in_progress')
            ->firstOrFail();

        $this->reconciliations->cancel($reconciliation);

        return back()->with('success', 'Reconciliation cancelled.');
    }

    public function history(BankAccount $bankAccount): Response
    {
        $this->authorizeManager();
        abort_unless($bankAccount->business_id === $this->tenantId(), 403);

        $sessions = BankReconciliation::where('bank_account_id', $bankAccount->id)
            ->orderByDesc('statement_date')
            ->get();

        return Inertia::render('BackOffice/ReconciliationHistory', [
            'account' => $bankAccount,
            'sessions' => $sessions,
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
