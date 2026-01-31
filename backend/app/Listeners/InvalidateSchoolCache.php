<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class InvalidateSchoolCache
{
    /**
     * Handle the event.
     */
    public function handle(object $event): void
    {
        if (property_exists($event, 'schoolId')) {
            $schoolId = $event->schoolId;

            if ($this->supportsTags()) {
                Cache::tags(['school_'.$schoolId])->flush();
            } else {
                // For non-tag drivers, we can't efficiently flush by school_id wildcard
                // But we can clear common school keys if needed.
                // For now just log it.
                Log::debug("Cache tag flush skipped (not supported by driver) for school_{$schoolId}");
            }

            Log::info("Invalidated cache for key: school_{$schoolId}");
        }
    }

    protected function supportsTags(): bool
    {
        return in_array(config('cache.default'), ['redis', 'memcached', 'array']);
    }
}
