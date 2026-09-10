<?php

namespace Database\Seeders\Simulation;

use App\Models\Coupon;
use App\Models\Payment;
use App\Models\ProductStock;
use App\Models\SheetLot;
use App\Models\SyncRecord;
use App\Models\Transaction;
use App\Services\Accounting\SalePostingService;
use App\Services\ApprovalService;
use App\Services\SyncProcessor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * SLS·05 / VR·07 / FX·06 / GLS·01 — the shop floor: opening and closing
 * shifts on every till, ringing up POS sales (multi-currency, discounts,
 * loyalty, coupons, container deposits, glass cuts, the occasional
 * void/refund), and keeping a short rolling window of recent sale ids so
 * later days have something real to void/refund against.
 */
class DailyOperationsSimulator
{
    /** @var array<int, array{id: string, business_id: string, location_id: string, total: float, currency: string}> */
    private array $recentTransactions = [];

    /**
     * "product_id|location_id" => quantity on hand, read from ProductStock
     * (the maintained, already-clamped-at-zero per-location balance) once
     * per day and decremented locally as sales are rung up — a store can
     * never sell what it doesn't have. Reset every forDay() call, since
     * purchasing/warehouse/transfer activity for that same simulated day
     * always runs after this one returns (see BusinessSimulationSeeder's
     * call order), so nothing else changes stock while this cache is live.
     *
     * @var array<string, float>
     */
    private array $stockCache = [];

    public function __construct(
        private readonly SyncProcessor $processor,
        private readonly ApprovalService $approvals,
        private readonly SalePostingService $salePosting,
    ) {}

    private function postSale(?Transaction $transaction): void
    {
        if ($transaction) {
            $this->salePosting->postIfReady($transaction);
        }
    }

    private function stockOnHand(string $productId, string $locationId): float
    {
        $key = $productId.'|'.$locationId;

        if (! array_key_exists($key, $this->stockCache)) {
            $this->stockCache[$key] = (float) (ProductStock::where('product_id', $productId)
                ->where('location_id', $locationId)->value('quantity') ?? 0);
        }

        return $this->stockCache[$key];
    }

    private function deductStockCache(string $productId, string $locationId, float $qty): void
    {
        $key = $productId.'|'.$locationId;
        $this->stockCache[$key] = max(0, ($this->stockCache[$key] ?? 0) - $qty);
    }

    public function forDay(SimContext $context, Carbon $date): void
    {
        if ($date->isSunday()) {
            return; // closed Sundays
        }

        $this->stockCache = [];

        foreach ($context->tills as $till) {
            $this->runTillDay($context, $till, $date);
        }

        $this->maybeReturnEmpties($context, $date);
        $this->maybeVoidOrRefundSomething($context, $date);
    }

    /**
     * FIN·10 — "return of empties against container deposits", independent
     * of any sale: a customer walks in with an empty gas cylinder and gets
     * the deposit back (cash) or nets it off their next refill.
     */
    private function maybeReturnEmpties(SimContext $context, Carbon $date): void
    {
        $containerProducts = array_filter($context->products, fn ($p) => $p['deposit_amount']);
        if (empty($containerProducts) || ! SimContext::chance(25)) {
            return;
        }

        $product = SimContext::pick(array_values($containerProducts));
        $locationId = SimContext::pick($context->sellingLocationIds);
        $qty = mt_rand(1, 3);
        $customer = $this->pickCustomer($context);

        $this->syncUpsert('container_deposit_ledger', (string) Str::uuid(), [
            'business_id' => $context->businessId,
            'location_id' => $locationId,
            'customer_id' => $customer['id'] ?? null,
            'container_product_id' => $product['id'],
            'transaction_id' => null,
            'quantity' => $qty,
            'deposit_amount_per_unit' => $product['deposit_amount'],
            'type' => 'return',
            'refund_method' => SimContext::chance(60) ? 'cash' : 'against_purchase',
            'reason' => 'Empty cylinder returned',
            'user_id' => $context->ownerUserId,
        ]);
    }

    private function runTillDay(SimContext $context, array $till, Carbon $date): void
    {
        $cashier = $this->pickCashier($context, $till['location_id']);
        if (! $cashier) {
            return;
        }

        $openedAt = $date->copy()->setTime(7, mt_rand(30, 59));
        $closedAt = $date->copy()->setTime(17, mt_rand(0, 45));
        $isWeekend = $date->isFriday() || $date->isSaturday();
        $saleCount = $isWeekend ? mt_rand(22, 42) : mt_rand(10, 26);

        $shiftId = (string) Str::uuid();
        $openingFloat = SimContext::between(80, 180);

        $this->syncUpsert('shifts', $shiftId, [
            'business_id' => $context->businessId,
            'location_id' => $till['location_id'],
            'till_id' => $till['id'],
            'cashier_id' => $cashier,
            'opened_at' => $openedAt->toIso8601String(),
            'status' => 'open',
            'opening_float' => $openingFloat,
        ]);

        $totals = ['cash' => 0.0, 'card' => 0.0, 'mobile' => 0.0, 'credit' => 0.0, 'discounts' => 0.0, 'refunds' => 0.0, 'count' => 0];

        for ($i = 0; $i < $saleCount; $i++) {
            $saleAt = $openedAt->copy()->addMinutes((int) ($i * (600 / max($saleCount, 1))) + mt_rand(0, 8));
            if ($saleAt->greaterThan($closedAt)) {
                $saleAt = $closedAt->copy()->subMinutes(mt_rand(1, 10));
            }

            $result = $this->ringUpSale($context, $till, $cashier, $saleAt);
            if (! $result) {
                continue;
            }

            $totals['count']++;
            $totals['discounts'] += $result['discount_total'];
            $totals[$result['method_bucket']] += $result['cash_equivalent'];
        }

        $expectedCash = $openingFloat + $totals['cash'];
        $countedCash = SimContext::chance(85) ? $expectedCash : round($expectedCash + SimContext::between(-6, 6), 2);

        $this->syncUpsert('shifts', $shiftId, [
            'business_id' => $context->businessId,
            'location_id' => $till['location_id'],
            'till_id' => $till['id'],
            'cashier_id' => $cashier,
            'opened_at' => $openedAt->toIso8601String(),
            'closed_at' => $closedAt->toIso8601String(),
            'status' => 'closed',
            'opening_float' => $openingFloat,
            'expected_cash' => round($expectedCash, 2),
            'counted_cash' => $countedCash,
            'variance' => round($countedCash - $expectedCash, 2),
            'total_sales' => round($totals['cash'] + $totals['card'] + $totals['mobile'] + $totals['credit'], 2),
            'cash_sales' => round($totals['cash'], 2),
            'card_sales' => round($totals['card'], 2),
            'mobile_money_sales' => round($totals['mobile'], 2),
            'credit_sales' => round($totals['credit'], 2),
            'total_refunds' => round($totals['refunds'], 2),
            'total_discounts' => round($totals['discounts'], 2),
            'transaction_count' => $totals['count'],
        ]);
        SimContext::backdate('shifts', $shiftId, $openedAt);
    }

    /**
     * @return array{discount_total: float, cash_equivalent: float, method_bucket: string}|null
     */
    private function ringUpSale(SimContext $context, array $till, string $cashierId, Carbon $saleAt): ?array
    {
        $lineCount = mt_rand(1, 5);
        $sellable = array_values(array_filter($context->products, fn ($p) => $p['track_stock'] || $p['is_sheet']));
        $lines = [];
        for ($i = 0; $i < $lineCount; $i++) {
            $lines[] = SimContext::pick($sellable);
        }

        $customer = $this->pickCustomer($context);
        $isCreditSale = $customer && $customer['is_account'] && SimContext::chance(35);

        $currency = $this->pickCurrency($context);
        $rate = $currency === $context->baseCurrency ? 1.0 : $context->currentRates[$currency];

        $subtotal = 0.0;
        $taxTotal = 0.0;
        $discountTotal = 0.0;
        $depositTotal = 0.0;
        $items = [];
        $movements = [];
        $deposits = [];

        $discountPct = match (true) {
            SimContext::chance(8) => SimContext::between(15, 30), // big — would trigger staged approval on a real till
            SimContext::chance(30) => SimContext::between(2, 10),
            default => 0.0,
        };

        foreach ($lines as $product) {
            $qty = $product['is_sheet'] ? 1 : (float) mt_rand(1, $product['unit'] === 'piece' && str_contains($product['name'], 'Brick') ? 200 : 6);
            $isSheetCut = $product['is_sheet'] && SimContext::chance(60);

            if ($product['is_sheet']) {
                $sale = $this->sellSheetProduct($context, $product, $till['location_id'], $isSheetCut, $cashierId, $saleAt);
                if (! $sale) {
                    continue;
                }
                $lineTotal = $sale['amount'];
                $unitPrice = $lineTotal;
                $qty = 1;
            } else {
                if ($product['track_stock']) {
                    // A store can only ever sell what's actually on the
                    // shelf at this location — cap to what's there, and
                    // skip the line entirely once it's out.
                    $onHand = $this->stockOnHand($product['id'], $till['location_id']);
                    $qty = min($qty, floor($onHand));
                    if ($qty < 1) {
                        continue;
                    }
                    $this->deductStockCache($product['id'], $till['location_id'], $qty);
                }

                $unitPrice = $product['price'];
                $lineTotal = round($unitPrice * $qty, 2);
            }

            $lineDiscount = round($lineTotal * $discountPct / 100, 2);
            $taxableAmount = $lineTotal - $lineDiscount;
            $exempt = $customer['tax_exempt'] ?? false;
            $lineTax = $exempt ? 0.0 : round($taxableAmount * 0.15, 2);

            $itemId = (string) Str::uuid();
            $items[] = [
                'id' => $itemId,
                'business_id' => $context->businessId,
                'transaction_id' => null, // filled once the transaction id is known
                'product_id' => $product['id'],
                'product_name' => $product['name'],
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount' => $lineDiscount,
                'tax_amount' => $lineTax,
                'line_total' => round($taxableAmount + $lineTax, 2),
            ];

            $subtotal += $lineTotal;
            $discountTotal += $lineDiscount;
            $taxTotal += $lineTax;

            if ($product['track_stock']) {
                $movements[] = ['product_id' => $product['id'], 'qty' => $qty, 'cost' => $product['cost_price']];
            }

            if ($product['deposit_amount'] && SimContext::chance(70)) {
                $depositAmount = $product['deposit_amount'] * $qty;
                $depositTotal += $depositAmount;
                $deposits[] = ['product_id' => $product['id'], 'qty' => $qty, 'amount' => $product['deposit_amount']];
            }
        }

        if (empty($items)) {
            return null;
        }

        $total = round($subtotal - $discountTotal + $taxTotal + $depositTotal, 2);
        if ($total <= 0) {
            return null;
        }

        $transactionId = (string) Str::uuid();
        $status = $isCreditSale ? 'credit_sale' : 'completed';

        $this->syncUpsert('transactions', $transactionId, [
            'business_id' => $context->businessId,
            'location_id' => $till['location_id'],
            'user_id' => $cashierId,
            'customer_id' => $customer['id'] ?? null,
            'subtotal' => round($subtotal, 2),
            'tax_total' => round($taxTotal, 2),
            'discount_total' => round($discountTotal, 2),
            'deposit_total' => round($depositTotal, 2),
            'surcharge_total' => 0,
            'total' => $total,
            'base_currency' => $context->baseCurrency,
            'status' => $status,
            'sale_number' => 'SALE-'.$saleAt->format('Ymd').'-'.strtoupper(Str::random(5)),
            'created_at' => $saleAt->toIso8601String(),
        ]);

        foreach ($items as $item) {
            $item['transaction_id'] = $transactionId;
            $this->syncUpsert('transaction_items', $item['id'], $item);
        }

        if ($taxTotal > 0) {
            $this->syncUpsert('transaction_taxes', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'transaction_id' => $transactionId,
                'tax_rate_id' => $context->taxRateId,
                'tax_name' => 'VAT',
                'rate_snapshot' => 15,
                'taxable_amount' => round($subtotal - $discountTotal, 2),
                'tax_amount' => round($taxTotal, 2),
            ]);
        }

        foreach ($movements as $movement) {
            $movementId = (string) Str::uuid();
            $this->syncUpsert('stock_movements', $movementId, [
                'business_id' => $context->businessId,
                'location_id' => $till['location_id'],
                'product_id' => $movement['product_id'],
                'type' => 'sale',
                'quantity_change' => -$movement['qty'],
                'unit_cost' => $movement['cost'],
                'running_avg_cost' => $movement['cost'],
                'reference_id' => $transactionId,
                'user_id' => $cashierId,
            ]);
            SimContext::backdate('stock_movements', $movementId, $saleAt);
        }

        foreach ($deposits as $deposit) {
            $this->syncUpsert('container_deposit_ledger', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'location_id' => $till['location_id'],
                'customer_id' => $customer['id'] ?? null,
                'container_product_id' => $deposit['product_id'],
                'transaction_id' => $transactionId,
                'quantity' => $deposit['qty'],
                'deposit_amount_per_unit' => $deposit['amount'],
                'type' => 'issue',
                'reason' => 'Deposit collected on sale',
                'user_id' => $cashierId,
            ]);
        }

        $methodBucket = $this->recordPayments($context, $transactionId, $total, $currency, $rate, $isCreditSale, $customer, $till, $saleAt, $cashierId);

        // Loyalty accrual for known account/regular customers on a cash-ish sale.
        if ($customer && SimContext::chance(40)) {
            $this->syncUpsert('loyalty_transactions', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'customer_id' => $customer['id'],
                'transaction_id' => $transactionId,
                'points' => round($total * 0.05, 2),
                'type' => 'earn',
                'note' => 'Points earned on sale',
            ]);
        }

        // Occasional coupon redemption.
        if (SimContext::chance(4)) {
            $this->maybeRedeemCoupon($context, $subtotal - $discountTotal);
        }

        // A big manual discount is the kind that would escalate through
        // staged approval on the till (SLS·05) — leave a matching record.
        if ($discountPct >= 15) {
            $approval = $this->approvals->request(
                $context->businessId, 'Transaction', $transactionId, 'apply_discount', $cashierId,
                ['percent' => $discountPct, 'lead_item' => $items[0]['product_name'] ?? null],
            );
            $manager = $this->pickManager($context, $till['location_id']) ?? $context->ownerUserId;
            $this->approvals->resolve($approval->id, $manager, 'approved', 'Discount authorised');
        }

        $this->recentTransactions[] = [
            'id' => $transactionId, 'business_id' => $context->businessId,
            'location_id' => $till['location_id'], 'total' => $total, 'currency' => $currency,
            'cashier_id' => $cashierId, 'sold_at' => $saleAt->toIso8601String(),
        ];
        if (count($this->recentTransactions) > 400) {
            array_shift($this->recentTransactions);
        }

        return [
            'discount_total' => $discountTotal,
            'cash_equivalent' => $total,
            'method_bucket' => $methodBucket,
        ];
    }

    /**
     * Payments are written directly (not through SyncProcessor) and posted
     * with a single explicit postIfReady() call once every payment for this
     * sale exists. SalePostingService::postIfReady() is designed to be
     * called repeatedly and no-op until everything has landed — but it
     * keys "already handled" purely off a JournalHeader existing for this
     * sale, with no regard for whether that journal's own status is
     * 'posted' or an incomplete 'draft' (see its class docblock). Two
     * separate payment upserts for a split payment would each trigger
     * SyncProcessor's normal per-payment posting attempt, so the FIRST one
     * — created before the second payment exists — would post (or, more
     * likely, draft as unbalanced) a journal missing the second payment
     * entirely, and that stuck draft would then permanently block the
     * second payment's own trigger from ever completing it. Writing both
     * rows first and posting once sidesteps that race rather than leaving
     * broken books in a dataset meant to be a *good* simulation.
     *
     * @return string cash|card|mobile|credit, for shift cash-up totals
     */
    private function recordPayments(
        SimContext $context, string $transactionId, float $total, string $currency, float $rate,
        bool $isCreditSale, ?array $customer, array $till, Carbon $saleAt, string $cashierId,
    ): string {
        $transaction = Transaction::find($transactionId);
        $toFace = fn (float $usd) => $currency === $context->baseCurrency ? $usd : round($usd * $rate, 2);

        if ($isCreditSale) {
            Payment::create([
                'transaction_id' => $transactionId, 'method' => 'credit', 'amount' => $toFace($total),
                'currency_code' => $currency, 'exchange_rate_used' => $rate, 'base_equivalent' => $total,
                'change_given' => 0,
            ]);

            $this->syncUpsert('credit_transactions', (string) Str::uuid(), [
                'business_id' => $context->businessId,
                'customer_id' => $customer['id'], 'transaction_id' => $transactionId,
                'amount' => $total, 'type' => 'purchase', 'method' => 'account',
            ]);

            $this->postSale($transaction);

            return 'credit';
        }

        $method = match (true) {
            SimContext::chance(55) => 'cash',
            SimContext::chance(60) => 'ecocash',
            default => 'card',
        };

        $bucket = match ($method) {
            'cash' => 'cash', 'ecocash' => 'mobile', default => 'card',
        };

        $changeGiven = 0.0;

        // Occasional split payment (part cash, part ecocash/card).
        if (SimContext::chance(12)) {
            $firstShareUsd = round($total * SimContext::between(0.4, 0.7), 2);
            $secondShareUsd = round($total - $firstShareUsd, 2);
            $secondMethod = $method === 'cash' ? 'ecocash' : 'cash';

            Payment::create([
                'transaction_id' => $transactionId, 'method' => $method, 'amount' => $toFace($firstShareUsd),
                'currency_code' => $currency, 'exchange_rate_used' => $rate, 'base_equivalent' => $firstShareUsd,
            ]);
            Payment::create([
                'transaction_id' => $transactionId, 'method' => $secondMethod, 'amount' => $toFace($secondShareUsd),
                'currency_code' => $currency, 'exchange_rate_used' => $rate, 'base_equivalent' => $secondShareUsd,
            ]);

            $this->postSale($transaction);

            return $bucket;
        }

        if ($method === 'cash' && $currency === $context->baseCurrency && SimContext::chance(20)) {
            // Tendered a round note, change owed if the drawer's short on coins.
            $changeGiven = SimContext::between(0.1, 3.0);
        }

        Payment::create([
            'transaction_id' => $transactionId, 'method' => $method, 'amount' => $toFace($total),
            'currency_code' => $currency, 'exchange_rate_used' => $rate, 'base_equivalent' => $total,
            'change_given' => $changeGiven,
        ]);

        $this->postSale($transaction);

        if ($changeGiven > 0 && SimContext::chance(15)) {
            $ledgerId = (string) Str::uuid();
            $this->syncUpsert('change_owed_ledger', $ledgerId, [
                'business_id' => $context->businessId, 'location_id' => $till['location_id'],
                'customer_id' => $customer['id'] ?? null, 'transaction_id' => $transactionId,
                'amount' => $changeGiven, 'currency_code' => $currency, 'type' => 'issue',
                'reason' => 'Till short of change', 'user_id' => $cashierId,
            ]);

            if (SimContext::chance(70)) {
                $this->syncUpsert('change_owed_ledger', (string) Str::uuid(), [
                    'business_id' => $context->businessId, 'location_id' => $till['location_id'],
                    'customer_id' => $customer['id'] ?? null, 'transaction_id' => $transactionId,
                    'amount' => -$changeGiven, 'currency_code' => $currency, 'type' => 'settle',
                    'reason' => 'Change settled', 'user_id' => $cashierId,
                ]);
            }
        }

        return $bucket;
    }

    /**
     * @return array{amount: float}|null
     */
    private function sellSheetProduct(SimContext $context, array $product, string $locationId, bool $isCut, string $userId, Carbon $saleAt): ?array
    {
        $lot = SheetLot::where('business_id', $context->businessId)
            ->where('product_id', $product['id'])
            ->where('location_id', $locationId)
            ->where('status', 'available')
            ->where('area', '>', 0.5)
            ->inRandomOrder()
            ->first();

        if (! $lot) {
            return null;
        }

        $sheetArea = (float) $product['sheet_width'] * (float) $product['sheet_height'] / 1_000_000; // mm² -> m²
        $pricePerSqm = $sheetArea > 0 ? $product['price'] / $sheetArea : 0.0;

        if (! $isCut) {
            $lot->update(['status' => 'exhausted', 'area' => 0]);

            return ['amount' => round($product['price'], 2)];
        }

        $maxWidth = min((float) $product['sheet_width'], sqrt((float) $lot->area * 1_000_000) + 200);
        $cutWidth = SimContext::between(300, min(1800, $maxWidth), 0);
        $cutHeight = SimContext::between(300, min(1800, $maxWidth), 0);
        $cutArea = round($cutWidth * $cutHeight / 1_000_000, 4);

        if ($cutArea >= (float) $lot->area) {
            $cutArea = (float) $lot->area;
        }

        $cutId = (string) Str::uuid();
        $this->syncUpsert('sheet_cuts', $cutId, [
            'business_id' => $context->businessId,
            'sheet_lot_id' => $lot->id, 'width' => $cutWidth, 'height' => $cutHeight,
            'area' => $cutArea, 'user_id' => $userId, 'cut_at' => $saleAt->toIso8601String(),
        ]);

        $remaining = round((float) $lot->area - $cutArea, 4);
        $lot->update(['area' => max(0, $remaining), 'status' => $remaining <= 0.05 ? 'exhausted' : 'available']);

        return ['amount' => round($cutArea * $pricePerSqm, 2)];
    }

    private function maybeRedeemCoupon(SimContext $context, float $eligibleAmount): void
    {
        $coupon = Coupon::where('business_id', $context->businessId)
            ->where('is_active', true)
            ->where('min_order_amount', '<=', $eligibleAmount)
            ->inRandomOrder()->first();

        if (! $coupon || ($coupon->max_uses && $coupon->uses_count >= $coupon->max_uses)) {
            return;
        }

        $this->syncUpsert('coupons', $coupon->id, [
            'business_id' => $coupon->business_id, 'code' => $coupon->code, 'description' => $coupon->description,
            'type' => $coupon->type, 'value' => (float) $coupon->value, 'min_order_amount' => (float) $coupon->min_order_amount,
            'max_uses' => $coupon->max_uses, 'uses_count' => $coupon->uses_count + 1, 'is_active' => true,
            'expires_at' => $coupon->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Rare void/refund from the last few days' sales, run through the same
     * approval-gated path a real till push would need (VR·07).
     */
    private function maybeVoidOrRefundSomething(SimContext $context, Carbon $date): void
    {
        if (empty($this->recentTransactions) || ! SimContext::chance(6)) {
            return;
        }

        $sale = $this->recentTransactions[array_rand($this->recentTransactions)];
        $transaction = Transaction::find($sale['id']);
        if (! $transaction || in_array($transaction->status, ['voided', 'refunded'], true)) {
            return;
        }

        $isVoid = SimContext::chance(50);
        $action = $isVoid ? 'void_transaction' : 'refund_transaction';
        $newStatus = $isVoid ? 'voided' : 'refunded';
        $reason = $isVoid ? 'Rung up in error' : 'Customer return';

        $cashier = $sale['cashier_id'];
        $manager = $this->pickManager($context, $sale['location_id']) ?? $context->ownerUserId;

        $approval = $this->approvals->request(
            $context->businessId, 'Transaction', $sale['id'], $action, $cashier,
            ['reason' => $reason],
        );
        $this->approvals->resolve($approval->id, $manager, 'approved', $isVoid ? 'Confirmed void' : 'Refund approved');

        // Full current-state payload — SyncProcessor's upsert is a total
        // replace, so every column has to be resent, not just status.
        $voidPayload = [
            'business_id' => $transaction->business_id,
            'location_id' => $transaction->location_id,
            'user_id' => $transaction->user_id,
            'customer_id' => $transaction->customer_id,
            'subtotal' => (float) $transaction->subtotal,
            'tax_total' => (float) $transaction->tax_total,
            'discount_total' => (float) $transaction->discount_total,
            'deposit_total' => (float) $transaction->deposit_total,
            'surcharge_total' => (float) $transaction->surcharge_total,
            'total' => (float) $transaction->total,
            'base_currency' => $transaction->base_currency,
            'status' => $newStatus,
            'sale_number' => $transaction->sale_number,
            'notes' => $transaction->notes,
            'void_reason' => $reason,
        ];

        // Raw process() call (not syncUpsert()) because this needs
        // trusted: false — mimics a real till's void/refund push, which
        // TillsController-style trust rules key off. SyncRecord is still
        // written by hand so the void reaches other devices via pull().
        $this->processor->process('transactions', $sale['id'], 'upsert', $voidPayload, trusted: false);

        SyncRecord::create([
            'business_id' => $voidPayload['business_id'],
            'table_name' => 'transactions',
            'record_uuid' => $sale['id'],
            'operation' => 'upsert',
            'payload' => $voidPayload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        $this->recentTransactions = array_values(array_filter(
            $this->recentTransactions,
            fn ($t) => $t['id'] !== $sale['id']
        ));
    }

    private function pickCashier(SimContext $context, string $locationId): ?string
    {
        $candidates = array_filter($context->staff, fn ($s) => ($context->locations[$s['location']] ?? null) === $locationId && $s['role'] === 'cashier');
        if (empty($candidates)) {
            $candidates = array_filter($context->staff, fn ($s) => ($context->locations[$s['location']] ?? null) === $locationId);
        }

        return empty($candidates) ? null : SimContext::pick(array_values($candidates))['id'];
    }

    private function pickManager(SimContext $context, string $locationId): ?string
    {
        $candidates = array_filter($context->staff, fn ($s) => in_array($s['role'], ['manager', 'business_owner'], true));
        if (empty($candidates)) {
            return null;
        }
        $local = array_filter($candidates, fn ($s) => ($context->locations[$s['location']] ?? null) === $locationId);

        return SimContext::pick(array_values(empty($local) ? $candidates : $local))['id'];
    }

    private function pickCustomer(SimContext $context): ?array
    {
        if (! SimContext::chance(35)) {
            return null; // anonymous walk-in
        }

        return SimContext::pick($context->customers);
    }

    private function pickCurrency(SimContext $context): string
    {
        return match (true) {
            SimContext::chance(65) => $context->baseCurrency,
            SimContext::chance(60) => 'ZWG',
            default => 'ZAR',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $table, string $uuid, array $payload): void
    {
        $this->processor->process($table, $uuid, 'upsert', $payload);

        // See MasterDataSeeder::syncUpsert() — process() alone never writes
        // a SyncRecord, so without this every simulated shift/sale/etc.
        // would exist in the database but never reach a device via pull().
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? MasterDataSeeder::BUSINESS_ID,
            'table_name' => $table,
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
