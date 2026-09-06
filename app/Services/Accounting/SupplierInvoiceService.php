<?php

namespace App\Services\Accounting;

use App\Models\Accounting\GlAccount;
use App\Models\Business;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Purchasing & Cash Vault Blueprint, part B — the GRNI (Goods Received Not
 * Invoiced) pattern: clears the GRN Suspense a GRV posted in part A, raises
 * the real Accounts Payable liability against the supplier, and posts the
 * difference between what the GRV estimated and what the supplier actually
 * billed to Purchase Price Variance. This is what makes Phase 11c's
 * Creditor Age Analysis and supplier statements show real data — the
 * moment this posts, that Cr Accounts Payable line is tagged
 * party_type=supplier and shows up there immediately.
 *
 * A manual BackOffice action, not sync-triggered — unlike SalePostingService
 * and GrvPostingService, it throws rather than silently skipping when
 * something's wrong, since there's a person waiting on the result.
 */
class SupplierInvoiceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly ChartOfAccountsSeeder $chartSeeder,
    ) {}

    public function recordInvoice(
        GoodsReceivedVoucher $grv,
        string $invoiceNumber,
        string $invoiceDate,
        float $amount,
        ?string $userId = null,
    ): SupplierInvoice {
        if (SupplierInvoice::where('grv_id', $grv->id)->exists()) {
            throw new RuntimeException("An invoice has already been recorded against {$grv->grv_number}.");
        }

        if (! $grv->supplier_id) {
            throw new RuntimeException("{$grv->grv_number} has no supplier on file — cannot raise a payable against it.");
        }

        $business = Business::find($grv->business_id);
        if (! $business?->accountingIsLive()) {
            throw new RuntimeException('Accounting has not been switched on for this business yet.');
        }

        return DB::transaction(function () use ($grv, $invoiceNumber, $invoiceDate, $amount, $userId) {
            $invoice = SupplierInvoice::create([
                'business_id' => $grv->business_id,
                'supplier_id' => $grv->supplier_id,
                'grv_id' => $grv->id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $invoiceDate,
                'amount' => $amount,
                'created_by_user_id' => $userId,
            ]);

            $this->allocateLandedCost($grv);
            $this->post($invoice, $this->grvValue($grv));

            return $invoice;
        });
    }

    private function grvValue(GoodsReceivedVoucher $grv): float
    {
        return round(
            GrvItem::where('grv_id', $grv->id)
                ->get()
                ->sum(fn (GrvItem $i) => (float) $i->quantity_accepted * (float) $i->unit_cost),
            4
        );
    }

    /**
     * Pro-rates the PO's additional_costs_json (freight/customs/handling)
     * across this GRV's items by their received value, so
     * grv_items.landed_unit_cost reflects what stock really cost to get on
     * the shelf. Visibility only — see the landed_unit_cost migration for
     * why this deliberately never touches Product.cost_price.
     */
    private function allocateLandedCost(GoodsReceivedVoucher $grv): void
    {
        $po = PurchaseOrder::find($grv->purchase_order_id);
        $additionalCosts = $po?->additional_costs_json;
        $totalAdditional = is_array($additionalCosts) ? array_sum($additionalCosts) : 0.0;

        $items = GrvItem::where('grv_id', $grv->id)->get();
        $totalValue = $items->sum(fn (GrvItem $i) => (float) $i->quantity_accepted * (float) $i->unit_cost);

        foreach ($items as $item) {
            $qty = (float) $item->quantity_accepted;
            $itemValue = $qty * (float) $item->unit_cost;
            $share = $totalValue > 0.005 ? ($itemValue / $totalValue) * $totalAdditional : 0.0;
            $landedUnitCost = $qty > 0.005 ? ($itemValue + $share) / $qty : (float) $item->unit_cost;

            $item->update(['landed_unit_cost' => round($landedUnitCost, 4)]);
        }
    }

    private function post(SupplierInvoice $invoice, float $grvValue): void
    {
        $businessId = $invoice->business_id;

        $grnSuspense = GlAccount::where('business_id', $businessId)->where('code', '2010')->first();
        $accountsPayable = GlAccount::where('business_id', $businessId)->where('code', '2000')->first();

        if (! $grnSuspense || ! $accountsPayable) {
            throw new RuntimeException('Chart of accounts is missing GRN Suspense or Accounts Payable.');
        }

        $ppv = $this->chartSeeder->ensureAccount(
            $businessId,
            'Cost of Sales',
            'Cost of Sales',
            ['code' => '5010', 'name' => 'Purchase Price Variance'],
        );

        $invoiceAmount = (float) $invoice->amount;
        $variance = round($invoiceAmount - $grvValue, 4);

        $header = $this->journals->createDraft(
            $businessId,
            $invoice->invoice_date->toDateString(),
            'supplier_invoice',
            $invoice->id,
            "Supplier invoice {$invoice->invoice_number}",
        );

        $this->journals->addLine($header, ['gl_account_id' => $grnSuspense->id, 'debit' => $grvValue]);
        $this->journals->addLine($header, [
            'gl_account_id' => $accountsPayable->id,
            'credit' => $invoiceAmount,
            'party_type' => 'supplier',
            'party_id' => $invoice->supplier_id,
        ]);

        // Positive variance = billed more than the GRV estimated (unfavorable,
        // an extra debit); negative = billed less (favorable, an extra credit).
        // Either way this is what keeps the journal balanced — see the class
        // doc comment's worked example in the Purchasing & Cash Vault Blueprint.
        if ($variance > 0.005) {
            $this->journals->addLine($header, ['gl_account_id' => $ppv->id, 'debit' => $variance]);
        } elseif ($variance < -0.005) {
            $this->journals->addLine($header, ['gl_account_id' => $ppv->id, 'credit' => abs($variance)]);
        }

        $this->journals->post($header);
    }
}
