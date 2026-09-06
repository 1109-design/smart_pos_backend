<?php

namespace App\Http\Controllers\BackOffice;

use App\Services\Accounting\FinancialStatementService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 11e — read-only financial statements, all derived live from the
 * general ledger (see FinancialStatementService). Owner/manager-only by
 * default: these expose the whole business's financial position, unlike
 * the operational reports on ReportsController.
 */
class FinancialStatementsController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function trialBalance(Request $request, FinancialStatementService $statements): Response
    {
        $this->authorize();

        $asOf = $request->date('as_of', 'Y-m-d') ?? now()->toDateString();

        return Inertia::render('BackOffice/TrialBalance', [
            'as_of' => $asOf,
            'report' => $statements->trialBalance($this->tenantId(), $asOf),
        ]);
    }

    public function incomeStatement(Request $request, FinancialStatementService $statements): Response
    {
        $this->authorize();

        $from = $request->date('from', 'Y-m-d') ?? now()->startOfMonth()->toDateString();
        $to = $request->date('to', 'Y-m-d') ?? now()->toDateString();

        return Inertia::render('BackOffice/IncomeStatement', [
            'from' => $from,
            'to' => $to,
            'report' => $statements->incomeStatement($this->tenantId(), $from, $to),
        ]);
    }

    public function balanceSheet(Request $request, FinancialStatementService $statements): Response
    {
        $this->authorize();

        $asOf = $request->date('as_of', 'Y-m-d') ?? now()->toDateString();

        return Inertia::render('BackOffice/BalanceSheet', [
            'as_of' => $asOf,
            'report' => $statements->balanceSheet($this->tenantId(), $asOf),
        ]);
    }

    private function authorize(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::VIEW_FINANCIAL_STATEMENTS),
            403,
            'Access denied.'
        );
    }
}
