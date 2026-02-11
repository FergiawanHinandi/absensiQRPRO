<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Routes queue jobs to school-specific queues to prevent noisy-neighbor.
 *
 * High-traffic or enterprise schools get dedicated queue workers.
 * Default schools share a common pool.
 */
class SchoolIsolatedDispatcher
{
    /**
     * Dispatch a job to the appropriate school-isolated queue.
     */
    public function dispatch(ShouldQueue $job, int $schoolId): void
    {
        $queue = $this->resolveQueue($schoolId);

        dispatch($job)->onQueue($queue);

        Log::channel('attendance_json')->debug('Job dispatched to school queue', [
            'job' => class_basename($job),
            'school_id' => $schoolId,
            'queue' => $queue,
        ]);
    }

    /**
     * Determine which queue a school's jobs should go to.
     */
    public function resolveQueue(int $schoolId): string
    {
        // Check if this school has a dedicated queue
        $dedicated = config('queue_schools.dedicated', []);

        if (isset($dedicated[$schoolId])) {
            return $dedicated[$schoolId];
        }

        // Check if school is in a priority tier
        $prioritySchools = config('queue_schools.priority', []);
        if (in_array($schoolId, $prioritySchools, true)) {
            return "attendance-priority-{$schoolId}";
        }

        // Default shared queue
        return config('queue_schools.default_queue', 'attendance-default');
    }

    /**
     * Get the projection queue name (always shared).
     */
    public function projectionQueue(): string
    {
        return config('queue_schools.projection_queue', 'projections');
    }

    /**
     * Get all configured queue names for supervisor/horizon.
     *
     * @return string[]
     */
    public function getAllQueues(): array
    {
        $queues = [
            config('queue_schools.default_queue', 'attendance-default'),
            $this->projectionQueue(),
        ];

        foreach (config('queue_schools.dedicated', []) as $queue) {
            $queues[] = $queue;
        }

        foreach (config('queue_schools.priority', []) as $schoolId) {
            $queues[] = "attendance-priority-{$schoolId}";
        }

        return array_unique($queues);
    }
}
