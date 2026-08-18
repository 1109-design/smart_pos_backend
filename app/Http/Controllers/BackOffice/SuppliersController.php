<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use App\Models\SyncRecord;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SuppliersController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorizeManager();

        $search = $request->string('search')->trim();

        $suppliers = Supplier::where('business_id', $this->tenantId())
            ->when($search, fn ($q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('contact_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
            )
            ->orderBy('name')
            ->get();

        return Inertia::render('BackOffice/Suppliers', [
            'suppliers' => $suppliers,
            'filters' => ['search' => $search->toString()],
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $data = $this->validateSupplier($request);

        $this->applySupplier($processor, (string) Str::uuid(), array_merge($data, ['is_active' => true]));

        return back()->with('success', 'Supplier added. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $supplier, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $existing = Supplier::where('business_id', $this->tenantId())->findOrFail($supplier);
        $data = $this->validateSupplier($request);

        $this->applySupplier($processor, $existing->id, array_merge($data, ['is_active' => $existing->is_active]));

        return back()->with('success', 'Supplier updated.');
    }

    public function toggleActive(string $supplier, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $existing = Supplier::where('business_id', $this->tenantId())->findOrFail($supplier);

        $this->applySupplier($processor, $existing->id, [
            'name' => $existing->name,
            'contact_name' => $existing->contact_name,
            'phone' => $existing->phone,
            'email' => $existing->email,
            'address' => $existing->address,
            'website' => $existing->website,
            'notes' => $existing->notes,
            'tax_number' => $existing->tax_number,
            'is_active' => ! $existing->is_active,
        ]);

        return back()->with('success', $existing->is_active ? 'Supplier archived.' : 'Supplier restored.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validateSupplier(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'tax_number' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * Apply through the same SyncProcessor a device push uses, then publish to
     * the sync stream so every device (including newly paired) receives it.
     *
     * @param  array<string, mixed>  $data
     */
    private function applySupplier(SyncProcessor $processor, string $uuid, array $data): void
    {
        $data['business_id'] = $this->tenantId();

        $processor->process('suppliers', $uuid, 'upsert', $data);

        SyncRecord::create([
            'business_id' => $data['business_id'],
            'table_name' => 'suppliers',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $data,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function authorizeManager(): void
    {
        abort_if(
            ! in_array(session('backoffice.role'), ['business_owner', 'manager']),
            403,
            'Access denied.'
        );
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
