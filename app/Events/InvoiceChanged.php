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
 * `location_id` is nullable (an invoice can be raised centrally, not tied to
 * a till location) — falls back to the business-wide channel when absent,
 * same reasoning as PurchaseOrderChanged.
 */
class InvoiceChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly ?string $locationId,
        public readonly string $invoiceId,
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
        return 'invoice.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['invoice_id' => $this->invoiceId];
    }
}
