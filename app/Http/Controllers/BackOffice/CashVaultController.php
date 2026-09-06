<?php

namespace App\Http\Controllers\BackOffice;

use App\Services\Accounting\CashVaultService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Purchasing & Cash Vault Blueprint, part C. Owner/manager only — reuses
 * MANAGE_TILLS, the closest existing permission to "handles cash movement
 * between tills and the business," rather than adding a brand new
 * permission for a single page.
 */
class CashVaultController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly CashVaultService $vault,
    ) {}

    public function index(): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();

        return Inertia::render('BackOffice/CashVault', [
            'balance' => $this->vault->balance($tenantId),
            'activity' => $this->vault->activity($tenantId),
        ]);
    }

    public function drop(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(fn () => $this->vault->recordTillDrop(
            $this->tenantId(), (float) $data['amount'], $data['date'], $data['note'] ?? null, $this->userId(),
        ), 'Till drop recorded.');
    }

    public function deposit(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->attempt(fn () => $this->vault->recordBankDeposit(
            $this->tenantId(), (float) $data['amount'], $data['date'], $data['note'] ?? null, $this->userId(),
        ), 'Bank deposit recorded.');
    }

    public function count(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'counted_amount' => ['required', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
        ]);

        try {
            $variance = $this->vault->recordCount($this->tenantId(), (float) $data['counted_amount'], $data['date'], $this->userId());
        } catch (RuntimeException $e) {
            return back()->withErrors(['counted_amount' => $e->getMessage()]);
        }

        $message = $variance == 0.0
            ? 'Count matches the ledger — nothing to post.'
            : 'Count recorded, variance of '.number_format(abs($variance), 2).' ('.($variance > 0 ? 'surplus' : 'shortfall').') posted.';

        return back()->with('success', $message);
    }

    private function attempt(callable $action, string $successMessage): RedirectResponse
    {
        try {
            $action();
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', $successMessage);
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
