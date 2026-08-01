<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cache Stampede Detected Event
 * 
 * Fired when multiple processes are contending for the same cache lock,
 * indicating a potential cache stampede scenario.
 */
class CacheStampedeDetected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $cacheKey,
        public int $contentionCount
    ) {}
}
