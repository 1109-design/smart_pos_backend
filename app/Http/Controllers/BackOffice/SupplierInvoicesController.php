<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\GoodsReceivedVoucher;
use App\Services\Accounting\SupplierInvoiceService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Purchasing & Cash Vault Blueprint, part B — recording the supplier's
 * actual bill against a GRV. A manual BackOffice-only action: this is
 * exactly the bookkeeping half of purchasing that belongs at the web
 * portal level, separate from receiving itself (which stays cashier-level,
 * unchanged — see GrvPostingService).
 */
class SupplierInvoicesController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly SupplierInvoiceService $invoices,
    ) {}

    public function store(Request $request, string $grv): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $record = GoodsReceivedVoucher::where('business_id', $tenantId)->findOrFail($grv);

        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:100'],
            'invoice_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        try {
            $this->invoices->recordInvoice(
                $record,
                $data['invoice_number'],
                $data['invoice_date'],
                (float) $data['amount'],
                $this->userId(),
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', "Invoice {$data['invoice_number']} recorded against {$record->grv_number}.");
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_PURCHASE_ORDERS),
            403,
            'Access denied.'
        );
    }
}
