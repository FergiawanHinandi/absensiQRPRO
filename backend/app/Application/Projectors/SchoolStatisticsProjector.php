<?php

declare(strict_types=1);

namespace App\Application\Projectors;

use App\Domain\Attendance\Events\AttendanceRecorded;
use App\Domain\Subscription\Events\SubscriptionExtended;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Projects school-level statistics from domain events.
 *
 * Maintains aggregate counters for school dashboards.
 */
class SchoolStatisticsProjector implements ShouldQueue
{
    public string $queue = 'projections';

    public int $tries = 3;

    /**
     * Handle attendance recorded — update daily school counter.
     */
    public function onAttendanceRecorded(AttendanceRecorded $event): void
    {
        try {
            // Invalidate cached dashboard data for this school
            cache()->forget("school_dashboard_{$event->schoolId}");
            cache()->forget("school_stats_{$event->schoolId}_" . ($event->attendanceDate ?: now()->format('Y-m-d')));
        } catch (\Throwable $e) {
            Log::channel('attendance_json')->warning('Cache invalidation failed in SchoolStatisticsProjector', [
                'school_id' => $event->schoolId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle subscription extended — log and invalidate subscription cache.
     */
    public function onSubscriptionExtended(SubscriptionExtended $event): void
    {
        try {
            cache()->forget("school_sub_{$event->schoolId}");
            cache()->forget("school_dashboard_{$event->schoolId}");
        } catch (\Throwable $e) {
            Log::channel('attendance_json')->warning('Cache invalidation failed for subscription extension', [
                'school_id' => $event->schoolId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Subscribe to events.
     *
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            AttendanceRecorded::class => 'onAttendanceRecorded',
            SubscriptionExtended::class => 'onSubscriptionExtended',
        ];
    }
}
