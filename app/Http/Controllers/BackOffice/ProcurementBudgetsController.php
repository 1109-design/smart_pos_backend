<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\ProcurementBudget;
use App\Models\SyncRecord;
use App\Services\BackOfficeAuthorizer;
use App\Services\SyncProcessor;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PUR·02 — period procurement budgets. Read-only enforcement lives in
 * SyncProcessor::gatePurchaseOrderStatus() (checked the moment a till
 * submits a PO, same chokepoint as the flat per-PO threshold); this
 * controller is where an owner/manager sets the budgets up — from
 * BackOffice or, now, the till's own Procurement Budgets screen, which
 * pushes through the same `procurement_budgets` sync table this
 * controller publishes to, so either origin converges identically.
 */
class ProcurementBudgetsController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly SyncProcessor $processor,
    ) {}

    public function index(): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();

        $budgets = ProcurementBudget::where('business_id', $tenantId)
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (ProcurementBudget $budget) => [
                'id' => $budget->id,
                'name' => $budget->name,
                'period_start' => $budget->period_start->toDateString(),
                'period_end' => $budget->period_end->toDateString(),
                'amount' => (float) $budget->amount,
                'spent' => $budget->spentSoFar(),
                'remaining' => $budget->remaining(),
                'is_current' => now()->between($budget->period_start, $budget->period_end),
            ]);

        return Inertia::render('BackOffice/ProcurementBudgets', [
            'budgets' => $budgets,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $id = (string) Str::uuid();
        $payload = [
            'business_id' => $this->tenantId(),
            'name' => $data['name'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'amount' => $data['amount'],
            'created_by_user_id' => $this->userId(),
        ];

        $this->processor->process('procurement_budgets', $id, 'upsert', $payload);
        SyncRecord::create([
            'business_id' => $this->tenantId(),
            'table_name' => 'procurement_budgets',
            'record_uuid' => $id,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        return back()->with('success', 'Procurement budget created.');
    }

    public function destroy(string $budget): RedirectResponse
    {
        $this->authorize();

        ProcurementBudget::where('business_id', $this->tenantId())->findOrFail($budget)->delete();

        SyncRecord::create([
            'business_id' => $this->tenantId(),
            'table_name' => 'procurement_budgets',
            'record_uuid' => $budget,
            'operation' => 'delete',
            'payload' => [],
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);

        return back()->with('success', 'Procurement budget removed.');
    }

    private function authorize(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_PURCHASE_ORDERS),
            403,
            'Access denied.'
        );
    }
}
