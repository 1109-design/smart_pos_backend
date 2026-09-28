<?php

namespace App\Http\Controllers\BackOffice;

use App\Models\Accounting\GlAccount;
use App\Services\Accounting\AccountRoleMappingService;
use App\Services\BackOfficeAuthorizer;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lets an owner see and reassign which GL account backs each abstract
 * posting role (see AccountRoleMappingService) used by the newer Salary/
 * Supplier-Payment/Asset posting flows — reuses MANAGE_JOURNAL_ENTRIES,
 * the closest existing permission for sensitive accounting configuration,
 * rather than adding a brand new one for a single page.
 */
class AccountRoleMappingsController extends BackOfficeController
{
    public function __construct(
        private readonly BackOfficeAuthorizer $authorizer,
        private readonly AccountRoleMappingService $mappings,
    ) {}

    public function index(): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $roles = collect($this->mappings->knownRoles())
            ->map(fn (string $role) => [
                'role' => $role,
                'account' => $this->mappings->resolve($tenantId, $role),
            ])
            ->values();

        $accounts = GlAccount::where('business_id', $tenantId)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return Inertia::render('BackOffice/AccountRoleMappings', [
            'roles' => $roles,
            'accounts' => $accounts,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->authorizeManager();

        $data = $request->validate([
            'role' => ['required', 'string', 'in:'.implode(',', $this->mappings->knownRoles())],
            'gl_account_id' => ['required', 'uuid'],
        ]);

        $account = GlAccount::where('business_id', $this->tenantId())
            ->where('id', $data['gl_account_id'])
            ->firstOrFail();

        $this->mappings->setMapping($this->tenantId(), $data['role'], $account->id);

        return back()->with('success', 'Account mapping updated.');
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_JOURNAL_ENTRIES),
            403,
            'Access denied.'
        );
    }
}
