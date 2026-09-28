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
 * Fires on creation only — an allocation is append-only (see
 * SyncProcessor::IMMUTABLE). Lets another device's supplier statement/age
 * analysis/outstanding-invoice screens refresh once a payment is applied.
 */
class SupplierPaymentAllocated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly string $supplierPaymentId,
        public readonly string $supplierInvoiceId,
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
        return 'supplier_payment.allocated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'supplier_payment_id' => $this->supplierPaymentId,
            'supplier_invoice_id' => $this->supplierInvoiceId,
        ];
    }
}
