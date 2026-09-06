<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;

/**
 * Base for every BackOffice controller.
 *
 * Single-database tenancy (see App\Models\Tenant docblock): there is no
 * global-scope safety net here except on `User`. Every tenant-owned query in
 * this namespace MUST be scoped with `->where('business_id', $this->tenantId())`
 * (or equivalent) — omitting it is exactly how the Dashboard/Reports/
 * Transactions cross-business leak happened (fixed 2026-08-17). tenantId()
 * and userId() used to be copy-pasted into all 18 BackOffice controllers
 * individually; they're centralized here so there's one definition to get
 * right instead of 18 to keep in sync.
 */
abstract class BackOfficeController extends Controller
{
    protected function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }

    protected function userId(): ?string
    {
        return session('backoffice')['user_id'] ?? null;
    }
}
