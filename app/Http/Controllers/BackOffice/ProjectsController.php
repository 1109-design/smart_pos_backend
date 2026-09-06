<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Expense;
use App\Models\Project;
use App\Models\Requisition;
use App\Services\BackOfficeAuthorizer;
use App\Services\ProjectService;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * PRJ·04 — job costing. A project collects two kinds of cost: stock issued
 * against it via a requisition (STK·03, purpose='project' — costed at
 * RequisitionItem::costTotal(), the unit cost snapshotted the moment stock
 * actually left, not today's product cost) and direct expenses tagged to it
 * (transport, labour — recorded at the till, see SyncProcessor's expenses
 * case). This is a costing/visibility report sitting alongside the
 * accounting system, not inside it — nothing here touches general_ledger.
 *
 * Reuses MANAGE_REQUISITIONS rather than a dedicated permission — projects
 * only exist as the thing a requisition or expense gets tagged against,
 * the same job-costing feature area, not an independent one.
 */
class ProjectsController extends BackOfficeController
{
    public function __construct(
        private readonly ProjectService $projects,
        private readonly BackOfficeAuthorizer $authorizer,
    ) {}

    public function index(): Response
    {
        $this->authorize();

        $tenantId = $this->tenantId();

        $projects = Project::where('business_id', $tenantId)
            ->latest()
            ->get()
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'reference' => $project->reference,
                'status' => $project->status,
                'budget' => $project->budget !== null ? (float) $project->budget : null,
                'spent' => $this->totalCost($project),
                'created_at' => $project->created_at->toIso8601String(),
            ]);

        return Inertia::render('BackOffice/Projects', [
            'projects' => $projects,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'budget' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->projects->create([
            'business_id' => $this->tenantId(),
            'name' => $data['name'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'budget' => $data['budget'] ?? null,
            'created_by_user_id' => $this->userId(),
        ]);

        return back()->with('success', 'Project created.');
    }

    public function show(string $project): Response
    {
        $this->authorize();

        $project = $this->findOwned($project);

        $requisitionLines = Requisition::where('project_id', $project->id)
            ->where('status', 'issued')
            ->with('items')
            ->get()
            ->flatMap(fn (Requisition $req) => $req->items->map(fn ($item) => [
                'source' => 'requisition',
                'reference' => $req->requisition_number,
                'description' => $item->product_name,
                'quantity' => (float) $item->quantity_issued,
                'amount' => $item->costTotal(),
                'date' => $req->issued_at?->toIso8601String(),
            ]));

        $expenseLines = Expense::where('project_id', $project->id)
            ->whereNull('deleted_at')
            ->orderBy('expense_date')
            ->get()
            ->map(fn (Expense $expense) => [
                'source' => 'expense',
                'reference' => $expense->category,
                'description' => $expense->description,
                'quantity' => null,
                'amount' => (float) $expense->base_equivalent,
                'date' => $expense->expense_date->toIso8601String(),
            ]);

        $lines = $requisitionLines->concat($expenseLines)->sortBy('date')->values();

        return Inertia::render('BackOffice/ProjectShow', [
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'reference' => $project->reference,
                'notes' => $project->notes,
                'status' => $project->status,
                'budget' => $project->budget !== null ? (float) $project->budget : null,
            ],
            'lines' => $lines,
            'total_cost' => round((float) $lines->sum('amount'), 4),
        ]);
    }

    public function close(string $project): RedirectResponse
    {
        $this->authorize();

        $this->projects->close($this->findOwned($project)->id);

        return back()->with('success', 'Project closed.');
    }

    public function reopen(string $project): RedirectResponse
    {
        $this->authorize();

        $this->projects->reopen($this->findOwned($project)->id);

        return back()->with('success', 'Project reopened.');
    }

    private function totalCost(Project $project): float
    {
        $requisitionCost = Requisition::where('project_id', $project->id)
            ->where('status', 'issued')
            ->with('items')
            ->get()
            ->flatMap(fn ($req) => $req->items)
            ->sum(fn ($item) => $item->costTotal());

        $expenseCost = Expense::where('project_id', $project->id)->whereNull('deleted_at')->sum('base_equivalent');

        return round((float) $requisitionCost + (float) $expenseCost, 4);
    }

    private function findOwned(string $projectId): Project
    {
        return Project::where('business_id', $this->tenantId())->findOrFail($projectId);
    }

    private function authorize(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_REQUISITIONS),
            403,
            'Access denied.'
        );
    }
}
