<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AdminMetricsUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $schoolId,
        public array $metrics
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('health.'.$this->schoolId)];
    }

    public function broadcastWith(): array
    {
        return $this->metrics;
    }

    public function broadcastAs(): string
    {
        return 'metrics.updated';
    }
}
