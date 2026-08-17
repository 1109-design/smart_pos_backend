<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\SyncRecord;
use App\Services\LocationService;
use App\Services\SyncProcessor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LocationsController extends Controller
{
    public function index(LocationService $locations): Response
    {
        $tenantId = $this->tenantId();

        // Businesses that pre-date multi-location support (or set up entirely
        // from the till) may have zero locations. Seed one so this page — and
        // Transfers/Products, which both depend on there being at least one —
        // is never a dead end.
        $locations->ensureDefaultLocation($tenantId);

        return Inertia::render('BackOffice/Locations', [
            'locations' => Location::where('business_id', $tenantId)
                ->orderBy('type')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request, SyncProcessor $processor): RedirectResponse
    {
        $data = $this->validatePayload($request);

        $this->applyThroughSyncPipeline($processor, (string) Str::uuid(), $data);

        return back()->with('success', 'Location created. Devices will receive it on their next sync.');
    }

    public function update(Request $request, string $location, SyncProcessor $processor): RedirectResponse
    {
        $existing = Location::where('business_id', $this->tenantId())->findOrFail($location);
        $data = $this->validatePayload($request);
        $data['is_active'] = $existing->is_active;

        $this->applyThroughSyncPipeline($processor, $location, $data);

        return back()->with('success', 'Location updated. Devices will receive it on their next sync.');
    }

    public function toggleActive(string $location, SyncProcessor $processor): RedirectResponse
    {
        $existing = Location::where('business_id', $this->tenantId())->findOrFail($location);

        $payload = [
            'business_id' => $existing->business_id,
            'parent_id' => $existing->parent_id,
            'name' => $existing->name,
            'type' => $existing->type,
            'address' => $existing->address,
            'phone' => $existing->phone,
            'email' => $existing->email,
            'can_sell' => (bool) $existing->can_sell,
            'can_receive' => (bool) $existing->can_receive,
            'is_active' => ! $existing->is_active,
        ];

        $processor->process('locations', $existing->id, 'upsert', $payload);
        $this->publishSyncRecord($existing->id, $payload);

        return back()->with('success', $payload['is_active'] ? 'Location restored.' : 'Location deactivated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request): array
    {
        $tenantId = $this->tenantId();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:shop,warehouse'],
            'parent_id' => ['nullable', 'string', Rule::exists('locations', 'id')->where('business_id', $tenantId)],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'can_sell' => ['boolean'],
            'can_receive' => ['boolean'],
        ]);

        return [
            'parent_id' => $validated['parent_id'] ?? null,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'can_sell' => $validated['can_sell'] ?? true,
            'can_receive' => $validated['can_receive'] ?? true,
            'is_active' => true,
        ];
    }

    private function applyThroughSyncPipeline(SyncProcessor $processor, string $uuid, array $data): void
    {
        $data['business_id'] = $this->tenantId();

        $processor->process('locations', $uuid, 'upsert', $data);
        $this->publishSyncRecord($uuid, $data);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $this->tenantId(),
            'table_name' => 'locations',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
