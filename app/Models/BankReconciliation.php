<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankReconciliation extends Model
{
    use HasUuids;

    protected $fillable = [
        'id', 'business_id', 'bank_account_id', 'statement_date', 'statement_balance',
        'status', 'started_by_user_id', 'started_at', 'completed_by_user_id', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'statement_date' => 'date',
            'statement_balance' => 'decimal:4',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /**
     * A reconciliation session can be started/completed/cancelled from any
     * device or BackOffice (see sync_service.dart's doc comment on why this
     * table is bidirectional). Without this guard, a device that started a
     * session and then went offline could resync its own stale 'in_progress'
     * snapshot after another device already completed or cancelled it,
     * silently regressing the status and wiping completed_by_user_id/
     * completed_at. Mirrors StockTransfer::isValidTransition() exactly.
     */
    public const TERMINAL_STATUSES = ['completed', 'cancelled'];

    /**
     * @var array<string, array<int, string>>
     */
    public const ALLOWED_TRANSITIONS = [
        'in_progress' => ['completed', 'cancelled'],
    ];

    public static function isValidTransition(?string $from, string $to): bool
    {
        if ($from === null || $from === $to) {
            return true;
        }

        if (in_array($from, self::TERMINAL_STATUSES, true)) {
            return false;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }
}
