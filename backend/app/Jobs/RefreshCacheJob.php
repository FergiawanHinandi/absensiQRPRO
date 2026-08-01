<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Job to refresh cache after invalidation
 * Used in cache warming and recovery scenarios
 */
class RefreshCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $cacheKey;
    public ?int $schoolId;

    public function __construct(array $data)
    {
        $this->cacheKey = $data['cache_key'];
        $this->schoolId = $data['school_id'] ?? null;
    }

    public function handle(): void
    {
        // Refresh cache by fetching fresh data
        $freshData = $this->fetchFreshData();
        
        Cache::put($this->cacheKey, $freshData, 3600);
        
        logger('Cache refreshed', [
            'cache_key' => $this->cacheKey,
            'school_id' => $this->schoolId,
        ]);
    }

    protected function fetchFreshData(): array
    {
        // Simulate fetching fresh data from database
        return ['refreshed' => true, 'timestamp' => now()];
    }
}
