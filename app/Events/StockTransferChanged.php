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
 * Broadcasts on both ends of the move — a dispatch matters to the receiving
 * location just as much as a receipt matters to the sending one — the same
 * two-channel reasoning TransferService::publishStock() already applies to
 * StockLevelChanged for a transfer's in-transit quantity.
 */
class StockTransferChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly string $fromLocationId,
        public readonly string $toLocationId,
        public readonly string $transferId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("business.{$this->businessId}.location.{$this->fromLocationId}"),
            new PrivateChannel("business.{$this->businessId}.location.{$this->toLocationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'stock_transfer.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['transfer_id' => $this->transferId];
    }
}
