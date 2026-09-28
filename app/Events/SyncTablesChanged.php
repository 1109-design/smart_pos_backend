<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per accepted sync push batch, naming the tables that changed.
 * Devices subscribed to their business channel quick-pull exactly those
 * tables instead of waiting for the next 10s periodic poll.
 *
 * Carries table names only, never row data — clients re-pull through the
 * normal sync engine (quickPullTables), keeping "pull is authoritative"
 * intact. Complements the domain-specific events (StockLevelChanged, …);
 * those fire from their own service hooks where richer payloads exist,
 * this one guarantees every pushed table fans out even where no hook
 * was ever added.
 */
class SyncTablesChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  list<string>  $tables
     */
    public function __construct(
        public readonly string $businessId,
        public readonly array $tables,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("business.{$this->businessId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'sync.tables_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['tables' => array_values($this->tables)];
    }
}
