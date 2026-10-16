<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Services\Audit\ServerAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Records server-side changes to audited models (Phase 12.2): who, when,
 * before and after, with device "server". Never fails the change itself.
 */
class AuditObserver
{
    private const NOISE = ['updated_at', 'created_at', 'remember_token', 'password', 'pin', 'pin_hash'];

    public function created(Model $model): void
    {
        $this->record($model, 'create', null, $this->clean($model->getAttributes()));
    }

    public function updated(Model $model): void
    {
        $changed = array_keys($this->clean($model->getChanges()));
        if ($changed === []) {
            return;
        }
        $before = $this->clean($model->getOriginal());
        $this->record($model, 'update', $before, [...$before, ...$this->clean($model->getAttributes())]);
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'delete', $this->clean($model->getOriginal()), null);
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function record(Model $model, string $action, ?array $before, ?array $after): void
    {
        if (ServerAudit::applyingDeviceSync() || $model instanceof AuditLog) {
            return;
        }
        try {
            AuditLog::create([
                'id' => (string) Str::uuid(),
                'business_id' => $model->getAttribute('business_id') ?? ($model->getTable() === 'businesses' ? $model->getKey() : null),
                'user_id' => Auth::id() !== null ? (string) Auth::id() : null,
                'device_id' => 'server',
                'model' => $model->getTable(),
                'record_id' => (string) $model->getKey(),
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function clean(array $attributes): array
    {
        return array_diff_key($attributes, array_flip(self::NOISE));
    }
}
