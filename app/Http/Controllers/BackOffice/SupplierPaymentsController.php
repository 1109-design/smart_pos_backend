<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Supplier;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Purchasing & Cash Vault Blueprint, part B — recording a payment made to a
 * supplier. Not tied to a specific invoice, matching the same "one running
 * balance, FIFO-aged" simplification Phase 11c's debtor side already uses.
 */
class SupplierPaymentsController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly SupplierPaymentService $payments,
    ) {}

    public function store(Request $request, string $supplier): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $record = Supplier::where('business_id', $tenantId)->findOrFail($supplier);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', 'in:cash,bank'],
            'reference' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $this->payments->recordPayment(
                $tenantId,
                $record->id,
                (float) $data['amount'],
                $data['payment_date'],
                $data['method'],
                $data['reference'] ?? null,
                $this->userId(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment recorded.');
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_SUPPLIERS),
            403,
            'Access denied.'
        );
    }
}
