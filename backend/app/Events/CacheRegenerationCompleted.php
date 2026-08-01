<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Cache Regeneration Completed Event
 * 
 * Fired when cache regeneration completes successfully.
 * Used for monitoring cache regeneration performance.
 */
class CacheRegenerationCompleted
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public string $cacheKey,
        public float $regenerationTime
    ) {}
}
