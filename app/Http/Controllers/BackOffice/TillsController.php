<?php

namespace App\Http\Controllers\BackOffice;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Shift;
use App\Models\SyncRecord;
use App\Models\Till;
use App\Models\TillLocationAudit;
use App\Services\BackOfficeAuthorizer;
use App\Services\SyncProcessor;
use App\Support\BackOfficePermission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TillsController extends Controller
{
    public function __construct(private readonly BackOfficeAuthorizer $authorizer) {}

    public function index(): Response
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $scope = $this->authorizer->currentLocationScope();

        $tills = Till::with('location:id,name,type')
            ->where('business_id', $tenantId)
            ->when($scope !== null, fn ($q) => $q->whereIn('location_id', $scope))
            ->orderBy('name')
            ->get();

        // Most recent reassignment per till, if any — replaces the two
        // mutable location_changed_at/by columns that used to live on Till
        // itself (see till_location_audits, the PoAuditLog-style append-only
        // log this now reads from).
        $latestMoves = TillLocationAudit::whereIn('till_id', $tills->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->unique('till_id')
            ->keyBy('till_id');

        $tills = $tills->map(fn (Till $till) => [
            ...$till->toArray(),
            'last_moved_at' => $latestMoves->get($till->id)?->created_at,
            'last_moved_by_user_name' => $latestMoves->get($till->id)?->changed_by_user_name,
        ]);

        return Inertia::render('BackOffice/Tills', [
            'tills' => $tills,
            'locations' => Location::where('business_id', $tenantId)
                ->where('is_active', true)
                ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope))
                ->orderBy('type')
                ->orderBy('name')
                ->get(['id', 'name', 'type']),
        ]);
    }

    /**
     * Move a till to a different location. This is the only sanctioned way a
     * till's location changes — SyncProcessor refuses the same change coming
     * from a device sync push (see the 'tills' case there), so a device
     * can never silently relocate a till on its own.
     */
    public function reassignLocation(Request $request, string $till, SyncProcessor $processor): RedirectResponse
    {
        $this->authorizeManager();

        $tenantId = $this->tenantId();
        $existing = Till::where('business_id', $tenantId)->findOrFail($till);

        $data = $request->validate([
            'location_id' => ['required', 'uuid', 'exists:locations,id'],
        ]);

        abort_unless(
            Location::where('id', $data['location_id'])->where('business_id', $tenantId)->exists(),
            404
        );

        // A user restricted to specific locations may only move a till
        // between locations they can actually see — not into or out of a
        // branch outside their own scope.
        $scope = $this->authorizer->currentLocationScope();
        if ($scope !== null) {
            abort_unless(
                in_array($existing->location_id, $scope, true) && in_array($data['location_id'], $scope, true),
                403,
                'You can only move a till between locations you have access to.'
            );
        }

        if ($data['location_id'] === $existing->location_id) {
            return back()->with('success', 'Till is already at that location.');
        }

        $hasOpenShift = Shift::where('till_id', $existing->id)->where('status', 'open')->exists();
        if ($hasOpenShift) {
            return back()->withErrors([
                'location_id' => 'This till has an open shift — close it before moving the till to a different location.',
            ]);
        }

        $payload = [
            'business_id' => $tenantId,
            'location_id' => $data['location_id'],
            'device_id' => $existing->device_id,
            'name' => $existing->name,
            'register_number' => $existing->register_number,
            'is_active' => $existing->is_active,
        ];

        $processor->process('tills', $existing->id, 'upsert', $payload, trusted: true);

        TillLocationAudit::create([
            'business_id' => $tenantId,
            'till_id' => $existing->id,
            'from_location_id' => $existing->location_id,
            'to_location_id' => $data['location_id'],
            'changed_by_user_id' => $this->userId(),
            'changed_by_user_name' => session('backoffice')['user_name'] ?? 'Unknown',
        ]);

        $this->publishSyncRecord($existing->id, $payload);

        return back()->with('success', 'Till moved. Devices will receive the change on their next sync.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function publishSyncRecord(string $uuid, array $payload): void
    {
        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? $this->tenantId(),
            'table_name' => 'tills',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }

    private function authorizeManager(): void
    {
        abort_unless(
            $this->authorizer->can($this->tenantId(), session('backoffice.role'), BackOfficePermission::MANAGE_TILLS),
            403,
            'Access denied.'
        );
    }

    private function userId(): ?string
    {
        return session('backoffice')['user_id'] ?? null;
    }

    private function tenantId(): ?string
    {
        return session('backoffice')['tenant_id'] ?? null;
    }
}
