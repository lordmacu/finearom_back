<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class IaForecastBatchProcessingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(public array $payload)
    {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('ia-forecast.batch');
    }

    public function broadcastAs(): string
    {
        return 'ia.forecast.batch.processing.updated';
    }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
