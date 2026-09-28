<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Customer;
use App\Models\Supplier;
use App\Services\Accounting\PartyLedgerService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Phase 11c — Debtor and Creditor Age Analysis, superseding what was
 * originally planned as part of Phase 8: those reports had nothing real to
 * age (Customer.credit_balance wasn't a ledger, Supplier had no balance at
 * all). Both read straight off the general ledger via PartyLedgerService,
 * so the numbers here always match the Accounts Receivable/Payable control
 * accounts and each party's own statement page.
 */
class AgingReportsController extends BackOfficeController
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function debtors(PartyLedgerService $ledger): Response
    {
        $this->authorize(BackOfficePermission::MANAGE_CUSTOMERS);

        $tenantId = $this->tenantId();

        $rows = Customer::where('business_id', $tenantId)
            ->get(['id', 'name'])
            ->map(function ($customer) use ($ledger, $tenantId) {
                $aging = $ledger->agingBuckets($tenantId, 'customer', $customer->id);

                return [
                    'id' => $customer->id,
                    'name' => $customer->name,
                    ...$aging,
                ];
            })
            ->filter(fn ($row) => $row['total_outstanding'] > 0.005 || $row['credit_balance'] > 0.005)
            ->sortByDesc('total_outstanding')
            ->values();

        return Inertia::render('BackOffice/AgeAnalysis', [
            'type' => 'debtor',
            'show_route_prefix' => '/office/customers',
            'rows' => $rows,
        ]);
    }

    public function creditors(PartyLedgerService $ledger): Response
    {
        $this->authorize(BackOfficePermission::MANAGE_SUPPLIERS);

        $tenantId = $this->tenantId();

        $rows = Supplier::where('business_id', $tenantId)
            ->get(['id', 'name'])
            ->map(function ($supplier) use ($ledger, $tenantId) {
                $aging = $ledger->agingBuckets($tenantId, 'supplier', $supplier->id);

                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    ...$aging,
                ];
            })
            ->filter(fn ($row) => $row['total_outstanding'] > 0.005 || $row['credit_balance'] > 0.005)
            ->sortByDesc('total_outstanding')
            ->values();

        return Inertia::render('BackOffice/AgeAnalysis', [
            'type' => 'creditor',
            'show_route_prefix' => '/office/suppliers',
            'rows' => $rows,
        ]);
    }

    private function authorize(string $permission): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), $permission),
            403,
            'Access denied.'
        );
    }
}
