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
 * `receiving_location_id` is nullable (a PO doesn't always have to name a
 * receiving location up front) — falls back to the business-wide channel
 * when absent, same fallback PurchaseOrderApprovalGate's own reasoning
 * would need if it ever became location-aware.
 */
class PurchaseOrderChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly ?string $locationId,
        public readonly string $purchaseOrderId,
    ) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        if ($this->locationId === null) {
            return [new PrivateChannel("business.{$this->businessId}")];
        }

        return [
            new PrivateChannel("business.{$this->businessId}.location.{$this->locationId}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'purchase_order.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['purchase_order_id' => $this->purchaseOrderId];
    }
}
