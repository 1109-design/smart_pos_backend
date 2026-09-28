<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\ApprovalRequest;
use App\Services\ApprovalService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Remote-approval queue for actions the till couldn't resolve with a local
 * manager PIN (see `ApprovalService`/till-side `requireApproval()`). This
 * is where an owner working off-site clears a void, refund, or rate change
 * a cashier raised with nobody else on shift.
 */
class ApprovalsController extends BackOfficeController
{
    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly BackOfficeAuthorizer $authorizer,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $status = $request->query('status', 'pending');

        $requests = ApprovalRequest::with(['requestedBy:id,name', 'approver:id,name'])
            ->where('business_id', $this->tenantId())
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('BackOffice/Approvals', [
            'requests' => $requests,
            'filters' => ['status' => $status],
        ]);
    }

    public function approve(Request $request, string $approvalRequest): RedirectResponse
    {
        $this->authorizeManager();
        $this->findOwned($approvalRequest);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->approvals->resolve($approvalRequest, $this->userId(), 'approved', $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('success', 'Request approved.');
    }

    public function reject(Request $request, string $approvalRequest): RedirectResponse
    {
        $this->authorizeManager();
        $this->findOwned($approvalRequest);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        try {
            $this->approvals->resolve($approvalRequest, $this->userId(), 'rejected', $data['reason'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('success', 'Request rejected.');
    }

    private function findOwned(string $id): ApprovalRequest
    {
        return ApprovalRequest::where('business_id', $this->tenantId())->findOrFail($id);
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_APPROVALS),
            403,
            'Access denied.'
        );
    }
}
