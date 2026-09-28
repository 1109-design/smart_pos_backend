<?php

namespace Database\Seeders\Simulation;

use App\Models\ApprovalRequest;
use App\Models\GoodsReceivedVoucher;
use App\Models\GrvItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SyncRecord;
use App\Services\Accounting\SupplierInvoiceService;
use App\Services\Accounting\SupplierPaymentService;
use App\Services\ApprovalService;
use App\Services\SyncProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * PUR·02 — purchase orders raised against a supplier, matched to a GRV on
 * delivery (GrvPostingService does that automatically off the resulting
 * 'receive' stock_movement — see SyncProcessor), then a supplier invoice
 * clearing GRN Suspense into real Accounts Payable and, eventually, a
 * payment. Lead time between raising and receiving is modelled with a
 * small in-memory queue rather than pretending everything arrives same-day.
 *
 * Glass sheet stock (GLS·01) never goes through this PO/GRV/invoice
 * pipeline at all — the app tracks it purely as SheetLot rows with no GRV
 * concept (see SheetYieldController's docblock: "the till writes to
 * directly") — so a glass restock here is recorded as a straight cash
 * purchase (an Expense) alongside the new SheetLots, not a PO.
 */
class PurchasingSimulator
{
    /** @var array<int, array{po_id: string, supplier_id: string, supplier_name: string, location_id: string, due_date: string, items: array<int, array{product_id: string, product_name: string, qty: float, unit_cost: float}>}> */
    private array $pendingReceipts = [];

    /** @var array<int, array{supplier_id: string, amount: float, due_date: string}> */
    private array $pendingPayments = [];

    private const SUPPLIER_CATEGORY_MAP = [
        'PPC Cement Zimbabwe' => 'Cement & Building Materials',
        'Turnall Roofing Products' => 'Roofing Sheets',
        'Steelmakers Zimbabwe' => 'Fasteners & Fixings',
        'National Glass & Aluminium' => 'Glass & Sheet Materials',
        'ZimFast Fasteners & Fixings' => 'Tools & Hardware',
        'Buildworld Timber Merchants' => 'Timber & Boards',
        'Puma Energy Gas Distributors' => 'Gas & Cylinders',
    ];

    public function __construct(
        private readonly SyncProcessor $processor,
        private readonly ApprovalService $approvals,
        private readonly SupplierInvoiceService $invoices,
        private readonly SupplierPaymentService $payments,
    ) {}

    public function forDay(SimContext $context, Carbon $date): void
    {
        $this->processDueReceipts($context, $date);
        $this->processDuePayments($context, $date);

        if ($date->isSunday()) {
            return;
        }

        // High enough to keep pace with two branches' worth of daily POS
        // demand (DailyOperationsSimulator now caps a sale to whatever
        // stock actually exists — a slower restock cadence than this just
        // means near-constant stockouts instead of a believably-supplied
        // hardware store).
        if (SimContext::chance(55)) {
            $this->raisePurchaseOrder($context, $date);
        }

        if (SimContext::chance(18)) {
            $this->walkInReceipt($context, $date);
        }
    }

    private function raisePurchaseOrder(SimContext $context, Carbon $date): void
    {
        $supplier = SimContext::pick($context->suppliers);
        $categoryName = self::SUPPLIER_CATEGORY_MAP[$supplier['name']] ?? null;
        $catalogue = array_values(array_filter($context->products, fn ($p) => $p['category'] === $categoryName && ! $p['is_sheet']));

        if ($categoryName === 'Glass & Sheet Materials') {
            $this->restockGlass($context, $date, $supplier);

            return;
        }

        if (empty($catalogue)) {
            return;
        }

        $lineCount = min(count($catalogue), mt_rand(4, 8));
        $lines = (array) array_rand($catalogue, $lineCount);

        $poId = (string) Str::uuid();
        $context->poSequence++;
        $poNumber = 'PO-'.$date->format('Y').'-'.str_pad((string) $context->poSequence, 5, '0', STR_PAD_LEFT);
        $ownerOrManager = $context->ownerUserId;

        $items = [];
        $totalOrdered = 0.0;
        foreach ($lines as $idx) {
            $product = $catalogue[$idx];
            $qty = (float) mt_rand(60, str_contains($product['unit'], 'bag') || $product['unit'] === 'piece' ? 600 : 150);
            $unitCost = round($product['cost_price'] * SimContext::between(0.97, 1.04), 2);
            $items[] = ['product_id' => $product['id'], 'product_name' => $product['name'], 'qty' => $qty, 'unit_cost' => $unitCost];
            $totalOrdered += $qty * $unitCost;
        }

        $this->syncUpsert($context, 'purchase_orders', $poId, [
            'business_id' => $context->businessId,
            'receiving_location_id' => $context->locations['warehouse'],
            'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'],
            'po_number' => $poNumber,
            'status' => 'draft',
            'total_ordered' => 0,
            'total_received' => 0,
            'expected_date' => $date->copy()->addDays(4)->toIso8601String(),
            'created_by_user_id' => $ownerOrManager,
        ]);

        foreach ($items as $item) {
            $this->syncUpsert($context, 'purchase_order_items', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'purchase_order_id' => $poId,
                'product_id' => $item['product_id'],
                'product_name' => $item['product_name'],
                'ordered_qty' => $item['qty'],
                'received_qty' => 0,
                'unit_cost' => $item['unit_cost'],
            ]);
        }

        // Submit for sending — SyncProcessor::gatePurchaseOrderStatus() will
        // hold this at pending_approval on its own if it's over threshold.
        $this->syncUpsert($context, 'purchase_orders', $poId, [
            'business_id' => $context->businessId,
            'receiving_location_id' => $context->locations['warehouse'],
            'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'],
            'po_number' => $poNumber,
            'status' => 'sent',
            'total_ordered' => round($totalOrdered, 2),
            'total_received' => 0,
            'expected_date' => $date->copy()->addDays(4)->toIso8601String(),
            'created_by_user_id' => $ownerOrManager,
        ]);
        SimContext::backdate('purchase_orders', $poId, $date);

        $po = PurchaseOrder::find($poId);
        if ($po?->status === 'pending_approval') {
            $approval = ApprovalRequest::where('subject_type', 'PurchaseOrder')->where('subject_id', $poId)
                ->where('status', 'pending')->first();
            if ($approval && SimContext::chance(85)) {
                $this->approvals->resolve($approval->id, $context->ownerUserId, 'approved', 'Spend authorised');
            } elseif ($approval) {
                $this->approvals->resolve($approval->id, $context->ownerUserId, 'rejected', 'Deferred to next month');

                return; // cancelled — nothing to receive
            }
        }

        $this->pendingReceipts[] = [
            'po_id' => $poId, 'supplier_id' => $supplier['id'], 'supplier_name' => $supplier['name'],
            'location_id' => $context->locations['warehouse'], 'due_date' => $date->copy()->addDays(mt_rand(2, 7))->toDateString(),
            'items' => $items,
        ];
    }

    private function processDueReceipts(SimContext $context, Carbon $date): void
    {
        $due = array_filter($this->pendingReceipts, fn ($r) => $r['due_date'] <= $date->toDateString());

        foreach ($due as $key => $receipt) {
            $receivedAt = $date->copy()->setTime(9, mt_rand(0, 45));
            $userId = $context->ownerUserId;

            foreach ($receipt['items'] as $item) {
                $movementId = (string) Str::uuid();
                $this->syncUpsert($context, 'stock_movements', $movementId, [
                    'business_id' => $context->businessId,
                    'location_id' => $receipt['location_id'],
                    'product_id' => $item['product_id'],
                    'type' => 'receive',
                    'quantity_change' => $item['qty'],
                    'unit_cost' => $item['unit_cost'],
                    'running_avg_cost' => $item['unit_cost'],
                    'reference_id' => $receipt['po_id'],
                    'user_id' => $userId,
                ]);
                SimContext::backdate('stock_movements', $movementId, $receivedAt);

                $poItemId = PurchaseOrderItem::where('purchase_order_id', $receipt['po_id'])
                    ->where('product_id', $item['product_id'])->value('id');
                if ($poItemId) {
                    $this->syncUpsert($context, 'purchase_order_items', $poItemId, [
                        'business_id' => $context->businessId,
                        'purchase_order_id' => $receipt['po_id'],
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'ordered_qty' => $item['qty'],
                        'received_qty' => $item['qty'],
                        'unit_cost' => $item['unit_cost'],
                        'received_unit_cost' => $item['unit_cost'],
                    ]);
                }
            }

            $this->syncUpsert($context, 'purchase_orders', $receipt['po_id'], [
                'business_id' => $context->businessId,
                'receiving_location_id' => $receipt['location_id'],
                'supplier_id' => $receipt['supplier_id'],
                'supplier_name' => $receipt['supplier_name'],
                'po_number' => PurchaseOrder::find($receipt['po_id'])?->po_number ?? '',
                'status' => 'received',
                'created_by_user_id' => $context->ownerUserId,
            ]);

            // GrvPostingService dates the GRV (and its journal) off the
            // stock_movement's created_at as read at insert time — before
            // this loop's own SimContext::backdate() call above ever runs
            // — so it lands on today's real date. Patch it to the
            // simulated receiving date now that the GRV actually exists.
            $grv = GoodsReceivedVoucher::where('purchase_order_id', $receipt['po_id'])->first();
            if ($grv) {
                $grv->update(['received_date' => $date->toDateString()]);
                SimContext::backdate('goods_received_vouchers', $grv->id, $receivedAt);
                SimContext::fixJournalDate('grv', $grv->id, $date);

                $amount = round(
                    GrvItem::where('grv_id', $grv->id)->get()->sum(fn ($i) => (float) $i->quantity_accepted * (float) $i->unit_cost)
                    * SimContext::between(0.98, 1.03), 2
                );

                try {
                    $invoice = $this->invoices->recordInvoice($grv, 'INV-'.strtoupper(Str::random(7)), $date->toDateString(), $amount, $context->ownerUserId);
                    SimContext::backdate('supplier_invoices', $invoice->id, $receivedAt);

                    if (SimContext::chance(55)) {
                        $payment = $this->payments->recordPayment($context->businessId, $receipt['supplier_id'], $amount, $date->toDateString(), SimContext::chance(70) ? 'bank' : 'cash', $invoice->invoice_number, $context->ownerUserId);
                        SimContext::backdate('supplier_payments', $payment->id, $receivedAt);
                    } else {
                        $this->pendingPayments[] = ['supplier_id' => $receipt['supplier_id'], 'amount' => $amount, 'due_date' => $date->copy()->addDays(mt_rand(14, 45))->toDateString()];
                    }
                } catch (\Throwable) {
                    // accounting not live yet, or already invoiced — skip quietly
                }
            }

            unset($this->pendingReceipts[$key]);
        }

        $this->pendingReceipts = array_values($this->pendingReceipts);
    }

    private function processDuePayments(SimContext $context, Carbon $date): void
    {
        $due = array_filter($this->pendingPayments, fn ($p) => $p['due_date'] <= $date->toDateString());

        foreach ($due as $key => $payment) {
            try {
                $recorded = $this->payments->recordPayment($context->businessId, $payment['supplier_id'], $payment['amount'], $date->toDateString(), SimContext::chance(70) ? 'bank' : 'cash', null, $context->ownerUserId);
                SimContext::backdate('supplier_payments', $recorded->id, $date->copy()->setTime(10, 0));
            } catch (\Throwable) {
                // ignore
            }
            unset($this->pendingPayments[$key]);
        }

        $this->pendingPayments = array_values($this->pendingPayments);
    }

    /**
     * Glass sheets bypass the PO/GRV pipeline entirely (see class docblock)
     * — new SheetLots plus a straight cash Expense for the delivery.
     */
    private function restockGlass(SimContext $context, Carbon $date, array $supplier): void
    {
        $glassProducts = array_values(array_filter($context->products, fn ($p) => $p['is_sheet']));
        if (empty($glassProducts)) {
            return;
        }

        $total = 0.0;
        foreach (['main', 'chitungwiza', 'warehouse'] as $locationKey) {
            $product = SimContext::pick($glassProducts);
            $sheets = mt_rand(4, 10);

            for ($i = 0; $i < $sheets; $i++) {
                $lotId = (string) Str::uuid();
                $this->syncUpsert($context, 'sheet_lots', $lotId, [
                    'business_id' => $context->businessId,
                    'product_id' => $product['id'],
                    'location_id' => $context->locations[$locationKey],
                    'original_width' => $product['sheet_width'],
                    'original_height' => $product['sheet_height'],
                    'area' => round($product['sheet_width'] * $product['sheet_height'] / 1_000_000, 4),
                    'status' => 'available',
                    'received_by_user_id' => $context->ownerUserId,
                ]);
                SimContext::backdate('sheet_lots', $lotId, $date);
            }

            $total += $sheets * $product['cost_price'];
        }

        $expenseId = (string) Str::uuid();
        $this->syncUpsert($context, 'expenses', $expenseId, [
            'business_id' => $context->businessId,
            'recorded_by_user_id' => $context->ownerUserId,
            'category' => 'purchases',
            'description' => "Glass sheet restock — {$supplier['name']}",
            'amount' => round($total, 2),
            'currency_code' => $context->baseCurrency,
            'base_equivalent' => round($total, 2),
            'exchange_rate' => 1,
            'payment_method' => 'bank_transfer',
            'expense_date' => $date->toIso8601String(),
        ]);
    }

    private function walkInReceipt(SimContext $context, Carbon $date): void
    {
        $location = SimContext::pick($context->sellingLocationIds);
        $trackable = array_values(array_filter($context->products, fn ($p) => $p['track_stock']));
        $product = SimContext::pick($trackable);
        $qty = mt_rand(15, 80);
        $receivedAt = $date->copy()->setTime(11, mt_rand(0, 45));

        $movementId = (string) Str::uuid();
        $this->syncUpsert($context, 'stock_movements', $movementId, [
            'business_id' => $context->businessId,
            'location_id' => $location,
            'product_id' => $product['id'],
            'type' => 'receive',
            'quantity_change' => $qty,
            'unit_cost' => round($product['cost_price'] * SimContext::between(0.98, 1.05), 2),
            'reason' => 'Walk-in cash-and-carry top-up (no PO)',
            'user_id' => $context->ownerUserId,
        ]);
        SimContext::backdate('stock_movements', $movementId, $receivedAt);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(SimContext $context, string $table, string $uuid, array $payload): void
    {
        $this->processor->process($table, $uuid, 'upsert', $payload);

        // See MasterDataSeeder::syncUpsert() — process() alone never writes
        // a SyncRecord, so without this every simulated PO/receipt/etc.
        // would exist in the database but never reach a device via pull().
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $context->businessId,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
