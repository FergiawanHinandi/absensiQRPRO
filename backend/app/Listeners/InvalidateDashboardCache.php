<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Invalidate dashboard cache when student attendance is recorded
 *
 * This ensures real-time data updates on dashboard without waiting
 * for cache expiration (5 minutes default)
 */
class InvalidateDashboardCache
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle($event): void
    {
        if (! property_exists($event, 'schoolId')) {
            return;
        }

        $schoolId = $event->schoolId;
        $attendance = $event->attendance ?? null;

        // Check if cache driver supports tags
        if ($this->supportsTags()) {
            // RECOMMENDED: Use cache tags for efficient invalidation
            $this->invalidateWithTags($schoolId);
        } else {
            // FALLBACK: Use individual key invalidation
            $this->invalidateWithKeys($schoolId, $event);
        }

        // Log cache invalidation for monitoring
        Log::info('Dashboard cache invalidated', [
            'school_id' => $schoolId,
            'event' => class_basename($event),
            'attendance_id' => $attendance->id ?? null,
        ]);
    }

    /**
     * Invalidate cache using tags (Redis/Memcached only)
     */
    protected function invalidateWithTags(int $schoolId): void
    {
        // Clear all dashboard-related cache for this school
        Cache::tags(['dashboard', "school_{$schoolId}"])->flush();

        // Also clear specific metric tags
        Cache::tags(['metrics', "school_{$schoolId}"])->flush();
        Cache::tags(['attendance_summary', "school_{$schoolId}"])->flush();
    }

    /**
     * Invalidate cache using individual keys (File/Database cache)
     */
    protected function invalidateWithKeys(int $schoolId, $event = null): void
    {
        // Dashboard metrics
        Cache::forget("dashboard_metrics_school_{$schoolId}");
        Cache::forget("dashboard_stats_school_{$schoolId}");

        // Attendance summaries
        Cache::forget("attendance_summary_school_{$schoolId}_today");
        Cache::forget("attendance_summary_school_{$schoolId}_week");
        Cache::forget("attendance_summary_school_{$schoolId}_month");

        // Recent attendances
        Cache::forget("recent_attendances_school_{$schoolId}");

        // Class-specific caches (if we know the class_id)
        if ($event && isset($event->attendance->schedule->class_id)) {
            $classId = $event->attendance->schedule->class_id;
            Cache::forget("class_attendance_school_{$schoolId}_class_{$classId}");
        }

        // Teacher dashboard (if applicable)
        if ($event && isset($event->attendance->schedule->teacher_id)) {
            $teacherId = $event->attendance->schedule->teacher_id;
            Cache::forget("teacher_dashboard_school_{$schoolId}_teacher_{$teacherId}");
        }
    }

    /**
     * Check if current cache driver supports tags
     */
    protected function supportsTags(): bool
    {
        $driver = config('cache.default');

        // Redis, Memcached, and Array support tags
        return in_array($driver, ['redis', 'memcached', 'array']);
    }
}
