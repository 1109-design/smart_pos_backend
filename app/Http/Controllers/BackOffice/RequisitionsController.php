<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Location;
use App\Models\Product;
use App\Models\Project;
use App\Models\Requisition;
use App\Services\BackOfficeAuthorizer;
use App\Services\LocationService;
use App\Services\RequisitionService;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * STK·03 — request → approve → issue. BackOffice-only for now: nothing on
 * the till creates or reads a requisition yet (unlike Transfers, which the
 * till also participates in). That's an acceptable scope cut here because
 * Storeman — the role that actually issues stock — is already BackOffice-
 * only (StoremanController has no till counterpart either), and the
 * RequisitionService/SyncProcessor plumbing underneath is already wired so
 * a future till "Request Stock" screen would be a pure UI addition.
 */
class RequisitionsController extends BackOfficeController
{
    public function __construct(
        private readonly RequisitionService $requisitions,
        private readonly BackOfficeAuthorizer $authorizer,
    ) {}

    public function index(LocationService $locations): Response
    {
        $this->authorize(BackOfficePermission::MANAGE_REQUISITIONS);

        $tenantId = $this->tenantId();
        $locations->ensureDefaultLocation($tenantId);

        $requisitions = Requisition::with(['location:id,name', 'requestedBy:id,name', 'approvedBy:id,name', 'issuedBy:id,name', 'items'])
            ->where('business_id', $tenantId)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/Requisitions', [
            'requisitions' => $requisitions,
            'locations' => Location::where('business_id', $tenantId)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'type']),
            'catalog' => Product::where('business_id', $tenantId)
                ->where('is_active', true)
                ->where('track_stock', true)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'barcode']),
            // All projects, not just active ones — a requisition raised
            // against a project that's since closed still needs its name to
            // resolve on this page; the create form filters to active-only
            // itself.
            'projects' => Project::where('business_id', $tenantId)->orderBy('name')->get(['id', 'name', 'status']),
            'can_issue' => $this->authorizer->can($tenantId, session('backoffice.role'), BackOfficePermission::MANAGE_STOREMAN),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize(BackOfficePermission::MANAGE_REQUISITIONS);

        $data = $request->validate([
            'location_id' => ['required', 'string', 'exists:locations,id'],
            'purpose' => ['required', 'in:general,project'],
            'project_id' => ['nullable', 'string', 'required_if:purpose,project'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'string', 'exists:products,id'],
            'items.*.quantity_requested' => ['required', 'numeric', 'min:0.0001'],
        ]);

        $this->assertLocationBelongsToTenant($data['location_id']);

        if ($data['purpose'] === 'project') {
            abort_unless(
                Project::where('business_id', $this->tenantId())->where('id', $data['project_id'])->exists(),
                422,
                'That project does not exist for this business.'
            );
        }

        $catalog = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->pluck('name', 'id');

        $this->requisitions->request([
            'business_id' => $this->tenantId(),
            'location_id' => $data['location_id'],
            'purpose' => $data['purpose'],
            'project_id' => $data['project_id'] ?? null,
            'notes' => $data['notes'] ?? null,
            'requested_by_user_id' => $this->userId(),
            'items' => collect($data['items'])->map(fn ($item) => [
                'product_id' => $item['product_id'],
                'product_name' => $catalog->get($item['product_id'], 'Unknown item'),
                'quantity_requested' => $item['quantity_requested'],
            ])->all(),
        ]);

        return back()->with('success', 'Requisition raised.');
    }

    public function approve(string $requisition): RedirectResponse
    {
        $this->authorize(BackOfficePermission::MANAGE_REQUISITIONS);
        $this->findOwned($requisition);

        try {
            $this->requisitions->approve($requisition, $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['requisition' => $e->getMessage()]);
        }

        return back()->with('success', 'Requisition approved.');
    }

    public function reject(string $requisition): RedirectResponse
    {
        $this->authorize(BackOfficePermission::MANAGE_REQUISITIONS);
        $this->findOwned($requisition);

        try {
            $this->requisitions->reject($requisition, $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['requisition' => $e->getMessage()]);
        }

        return back()->with('success', 'Requisition rejected.');
    }

    /**
     * Issuing is gated separately from requesting/approving — the spec's
     * "who does what" has a warehouse role (Storeman) physically handing
     * over stock, distinct from the manager/owner who approved it.
     */
    public function issue(Request $request, string $requisition): RedirectResponse
    {
        $this->authorize(BackOfficePermission::MANAGE_STOREMAN);
        $this->findOwned($requisition);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'string'],
            'items.*.quantity_issued' => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $this->requisitions->issue($requisition, $data['items'], $this->userId());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['requisition' => $e->getMessage()]);
        }

        return back()->with('success', 'Requisition issued — stock updated.');
    }

    public function cancel(string $requisition): RedirectResponse
    {
        $this->authorize(BackOfficePermission::MANAGE_REQUISITIONS);
        $this->findOwned($requisition);

        try {
            $this->requisitions->cancel($requisition);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['requisition' => $e->getMessage()]);
        }

        return back()->with('success', 'Requisition cancelled.');
    }

    private function findOwned(string $requisitionId): Requisition
    {
        return Requisition::where('business_id', $this->tenantId())->findOrFail($requisitionId);
    }

    private function assertLocationBelongsToTenant(string $locationId): void
    {
        abort_unless(
            Location::where('business_id', $this->tenantId())->where('id', $locationId)->exists(),
            404
        );
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
