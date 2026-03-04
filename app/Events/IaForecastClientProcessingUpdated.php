<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IaForecastClientProcessingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $clientId,
        public array $payload
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("ia-forecast.client.{$this->clientId}")];
    }

    public function broadcastAs(): string
    {
        return 'ia.forecast.client.processing.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
