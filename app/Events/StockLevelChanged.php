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
 * Carries only ids, not the new quantity — clients re-pull product_stock
 * through the normal sync engine (quickPullTables) rather than trusting a
 * broadcast payload as source of truth, matching the existing "pull is
 * server-authoritative" sync convention.
 */
class StockLevelChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly string $locationId,
        public readonly string $productId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("business.{$this->businessId}.location.{$this->locationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stock.level_changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['product_id' => $this->productId, 'location_id' => $this->locationId];
    }
}
