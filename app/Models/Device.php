<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Device extends Model
{
    protected $fillable = [
        'tenant_id',
        'name',
        'device_identifier',
        'device_code',
        'location_id',
        'token_id',
        'last_seen_at',
        'is_revoked',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'is_revoked' => 'boolean',
    ];

    /**
     * Every device gets a short code, unique within its tenant, when it is
     * registered. Devices prefix their document numbers with it
     * (JNL-{code}-{YYYYMM}-{####}) so numbers issued offline never collide.
     */
    protected static function booted(): void
    {
        static::creating(function (Device $device): void {
            if ($device->device_code === null && $device->tenant_id !== null) {
                $device->device_code = static::nextCodeFor($device->tenant_id);
            }
        });

        // A code is only unique within a tenant, so a device that moves to
        // another tenant takes the next free code there.
        static::updating(function (Device $device): void {
            if ($device->isDirty('tenant_id') && $device->tenant_id !== null) {
                $device->device_code = static::nextCodeFor($device->tenant_id);
            }
        });
    }

    /** The n-th code: 001, 002 … 00Z, 010 … ZZZ (base 36). */
    public static function codeFor(int $n): string
    {
        return str_pad(strtoupper(base_convert((string) $n, 10, 36)), 3, '0', STR_PAD_LEFT);
    }

    public static function nextCodeFor(string $tenantId): string
    {
        $highest = static::where('tenant_id', $tenantId)
            ->whereNotNull('device_code')
            ->pluck('device_code')
            ->map(fn (string $code): int => (int) base_convert($code, 36, 10))
            ->max() ?? 0;

        return static::codeFor($highest + 1);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The developer device(s) allowed to read every device's sync
     * diagnostics and database snapshots — see config/sync.php.
     */
    public function isDiagnosticsReader(): bool
    {
        return ! $this->is_revoked
            && in_array($this->device_identifier, config('sync.diagnostics_reader_devices', []), true);
    }

    /** The shop/warehouse this device operates from, if assigned. */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
