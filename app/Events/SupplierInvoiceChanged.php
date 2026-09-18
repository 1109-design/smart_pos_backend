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
 * Fires on both creation AND status changes — spec §33's own worked
 * example ("Accountant creates supplier invoice → Finance Manager's
 * approval inbox updates automatically" / "Manager approves → invoice
 * status automatically changes on other devices"), so unlike
 * SupplierPaymentRecorded (creation only), this must also fire on update.
 */
class SupplierInvoiceChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly string $supplierId,
        public readonly string $invoiceId,
        public readonly ?string $status,
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
        return 'supplier_invoice.changed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'supplier_id' => $this->supplierId,
            'invoice_id' => $this->invoiceId,
            'status' => $this->status,
        ];
    }
}
