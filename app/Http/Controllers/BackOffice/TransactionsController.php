<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Payment;
use App\Models\Transaction;
use App\Services\BackOfficeAuthorizer;
use App\Services\QrCodeGenerator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionsController extends BackOfficeController
{
    public function __invoke(Request $request, BackOfficeAuthorizer $authorizer): Response
    {
        $session = session('backoffice');
        $currency = $session['currency_code'] ?? 'USD';
        $tenantId = $this->tenantId();
        $locationIds = $authorizer->currentLocationScope();

        $from = $request->date('from', 'Y-m-d') ?? now()->subDays(6)->toDateString();
        $to = $request->date('to', 'Y-m-d') ?? now()->toDateString();
        $fiscalFilter = $request->string('fiscal')->toString();

        $fromStart = Carbon::parse($from)->startOfDay();
        $toEnd = Carbon::parse($to)->endOfDay();

        $transactions = Transaction::query()
            ->leftJoin('users', 'transactions.user_id', '=', 'users.id')
            ->where('transactions.business_id', $tenantId)
            ->whereBetween('transactions.created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('transactions.location_id', $locationIds))
            ->when($fiscalFilter !== '' && $fiscalFilter !== 'all', function ($query) use ($fiscalFilter) {
                if ($fiscalFilter === 'none') {
                    $query->whereNull('transactions.fiscal_status');
                } else {
                    $query->where('transactions.fiscal_status', $fiscalFilter);
                }
            })
            ->orderByDesc('transactions.created_at')
            ->select([
                'transactions.id',
                'transactions.sale_number',
                'transactions.status',
                'transactions.total',
                'transactions.tax_total',
                'transactions.base_currency',
                'transactions.fiscal_status',
                'transactions.fiscal_receipt_number',
                'transactions.fiscal_qr_code',
                'transactions.created_at',
                'users.name as cashier_name',
            ])
            ->paginate(25)
            ->withQueryString();

        $transactionIds = $transactions->getCollection()->pluck('id');
        $paymentsByTransaction = Payment::whereIn('transaction_id', $transactionIds)
            ->get(['transaction_id', 'method', 'amount', 'currency_code'])
            ->groupBy('transaction_id');

        $transactions->getCollection()->transform(function ($transaction) use ($paymentsByTransaction) {
            $transaction->payments = ($paymentsByTransaction[$transaction->id] ?? collect())
                ->map(fn ($payment) => [
                    'method' => $payment->method,
                    'amount' => $payment->amount,
                    'currency_code' => $payment->currency_code,
                ])
                ->values();
            // Render the QR image server-side only for fiscalised rows on this
            // page so the client needs no QR library.
            $transaction->fiscal_qr_data_uri = $transaction->fiscal_qr_code
                ? QrCodeGenerator::pngDataUri($transaction->fiscal_qr_code, 220)
                : null;

            return $transaction;
        });

        $fiscalSummary = Transaction::where('business_id', $tenantId)
            ->whereBetween('created_at', [$fromStart, $toEnd])
            ->when($locationIds, fn ($q) => $q->whereIn('location_id', $locationIds))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN fiscal_status = 'fiscalised' THEN 1 ELSE 0 END) as fiscalised,
                SUM(CASE WHEN fiscal_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN fiscal_status IN ('failed', 'not_configured') THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        return Inertia::render('BackOffice/Transactions', [
            'transactions' => $transactions,
            'fiscal_summary' => $fiscalSummary,
            'currency' => $currency,
            'filters' => ['from' => $from, 'to' => $to, 'fiscal' => $fiscalFilter ?: 'all'],
        ]);
    }
}
