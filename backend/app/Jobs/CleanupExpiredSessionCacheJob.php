<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Job to cleanup cache related to expired sessions
 * Maintains cache hygiene and prevents memory leaks
 */
class CleanupExpiredSessionCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $sessionId;

    public function __construct(array $data)
    {
        $this->sessionId = $data['session_id'];
    }

    public function handle(): void
    {
        // Clean up session-specific cache
        $cacheKey = "session:{$this->sessionId}:temp_data";
        Cache::forget($cacheKey);
        
        // Clean up any related cache keys
        $pattern = "session:{$this->sessionId}:*";
        $this->cleanupCachePattern($pattern);
        
        logger('Session cache cleaned up', [
            'session_id' => $this->sessionId,
        ]);
    }

    protected function cleanupCachePattern(string $pattern): void
    {
        // Simplified cleanup - in production would use Redis SCAN
        Cache::forget($pattern);
    }
}
