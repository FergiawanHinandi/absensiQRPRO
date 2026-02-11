<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Services\DashboardCacheService;
use Illuminate\Support\Facades\Log;

/**
 * Attendance Observer
 * 
 * Automatically invalidates cache when attendance records change
 * to ensure dashboard and reports show fresh data.
 */
class AttendanceObserver
{
    protected $cacheService;

    public function __construct(DashboardCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Handle the Attendance "created" event.
     */
    public function created(Attendance $attendance): void
    {
        $this->invalidateRelatedCaches($attendance, 'created');
    }

    /**
     * Handle the Attendance "updated" event.
     */
    public function updated(Attendance $attendance): void
    {
        $this->invalidateRelatedCaches($attendance, 'updated');
    }

    /**
     * Handle the Attendance "deleted" event.
     */
    public function deleted(Attendance $attendance): void
    {
        $this->invalidateRelatedCaches($attendance, 'deleted');
    }

    /**
     * Invalidate all related caches for an attendance record
     */
    protected function invalidateRelatedCaches(Attendance $attendance, string $action): void
    {
        try {
            // Invalidate dashboard cache for the school and date
            $this->cacheService->invalidateDashboard(
                $attendance->school_id,
                $attendance->attendance_date
            );

            // Invalidate class-specific cache
            $this->cacheService->invalidateClass(
                $attendance->class_id,
                $attendance->attendance_date
            );

            // Invalidate student-specific cache
            $this->cacheService->invalidateStudent(
                $attendance->student_id,
                $attendance->attendance_date
            );

            Log::channel('audit')->debug('attendance_cache_invalidated', [
                'action' => $action,
                'attendance_id' => $attendance->id,
                'school_id' => $attendance->school_id,
                'class_id' => $attendance->class_id,
                'student_id' => $attendance->student_id,
                'date' => $attendance->attendance_date,
            ]);
        } catch (\Exception $e) {
            // Don't fail the main operation if cache invalidation fails
            Log::error('Failed to invalidate attendance cache', [
                'attendance_id' => $attendance->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
