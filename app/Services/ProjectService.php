<?php

namespace App\Services;

use App\Models\Project;
use App\Models\SyncRecord;
use Illuminate\Support\Str;

/**
 * PRJ·04 — BackOffice-authored, synced DOWN to the till read-only (see the
 * Flutter `_pullOnlyTables` list) so a cashier tagging an expense against a
 * job can pick from the real list without needing BackOffice access.
 */
class ProjectService
{
    public function __construct(private readonly SyncProcessor $processor) {}

    /**
     * @param  array{business_id: string, name: string, created_by_user_id: string, reference?: string|null, notes?: string|null, budget?: float|null}  $data
     */
    public function create(array $data): Project
    {
        $id = (string) Str::uuid();

        $this->syncUpsert($id, [
            'business_id' => $data['business_id'],
            'name' => $data['name'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'budget' => $data['budget'] ?? null,
            'status' => 'active',
            'created_by_user_id' => $data['created_by_user_id'],
            'closed_at' => null,
        ]);

        return Project::findOrFail($id);
    }

    public function close(string $projectId): Project
    {
        $project = Project::findOrFail($projectId);

        $this->syncUpsert($project->id, $this->projectPayload($project, [
            'status' => 'closed',
            'closed_at' => now()->toIso8601String(),
        ]));

        return $project->fresh();
    }

    public function reopen(string $projectId): Project
    {
        $project = Project::findOrFail($projectId);

        $this->syncUpsert($project->id, $this->projectPayload($project, [
            'status' => 'active',
            'closed_at' => null,
        ]));

        return $project->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function projectPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $project->business_id,
            'name' => $project->name,
            'reference' => $project->reference,
            'notes' => $project->notes,
            'budget' => $project->budget,
            'status' => $project->status,
            'created_by_user_id' => $project->created_by_user_id,
            'closed_at' => $project->closed_at?->toIso8601String(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncUpsert(string $uuid, array $payload): void
    {
        $this->processor->process('projects', $uuid, 'upsert', $payload);

        SyncRecord::create([
            'business_id' => $payload['business_id'] ?? null,
            'table_name' => 'projects',
            'record_uuid' => $uuid,
            'operation' => 'upsert',
            'payload' => $payload,
            'source_updated_at' => now(),
            'synced_at' => now(),
        ]);
    }
}
