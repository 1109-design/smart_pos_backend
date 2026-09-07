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
 * Carries only the id — clients re-pull transactions/transaction_items
 * through the normal sync engine (quickPullTables) rather than trusting a
 * broadcast payload, matching every other event in this set.
 */
class TransactionRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $businessId,
        public readonly ?string $locationId,
        public readonly string $transactionId,
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
        return 'transaction.recorded';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['transaction_id' => $this->transactionId];
    }
}
