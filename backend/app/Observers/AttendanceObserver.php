<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Services\AttendanceSummaryService;
use Illuminate\Support\Facades\Log;

/**
 * Attendance Observer
 * 
 * Observes Attendance model events and updates daily class summaries in real-time.
 * Ensures dashboard data is always up-to-date without expensive aggregation queries.
 */
class AttendanceObserver
{
    /**
     * Attendance Summary Service
     */
    protected AttendanceSummaryService $summaryService;

    /**
     * Constructor
     */
    public function __construct(AttendanceSummaryService $summaryService)
    {
        $this->summaryService = $summaryService;
    }

    /**
     * Handle the Attendance "created" event.
     * 
     * When a new attendance record is created, update the summary for that class/date.
     */
    public function created(Attendance $attendance): void
    {
        $this->updateSummaryForAttendance($attendance, 'created');
    }

    /**
     * Handle the Attendance "updated" event.
     * 
     * When an attendance record is updated (e.g., status change), update the summary.
     */
    public function updated(Attendance $attendance): void
    {
        $this->updateSummaryForAttendance($attendance, 'updated');
    }

    /**
     * Handle the Attendance "deleted" event.
     * 
     * When an attendance record is deleted, update the summary to reflect the change.
     */
    public function deleted(Attendance $attendance): void
    {
        $this->updateSummaryForAttendance($attendance, 'deleted');
    }

    /**
     * Handle the Attendance "restored" event.
     * 
     * When a soft-deleted attendance is restored, update the summary.
     */
    public function restored(Attendance $attendance): void
    {
        $this->updateSummaryForAttendance($attendance, 'restored');
    }

    /**
     * Update summary for the given attendance record.
     * 
     * @param Attendance $attendance
     * @param string $event
     * @return void
     */
    protected function updateSummaryForAttendance(Attendance $attendance, string $event): void
    {
        try {
            // Get class_id from schedule relationship
            $schedule = $attendance->schedule;
            
            if (!$schedule) {
                Log::warning('Cannot update summary: attendance has no schedule', [
                    'attendance_id' => $attendance->id,
                    'schedule_id' => $attendance->schedule_id,
                    'event' => $event,
                ]);
                return;
            }

            $classId = $schedule->class_id;
            
            if (!$classId) {
                Log::warning('Cannot update summary: schedule has no class', [
                    'attendance_id' => $attendance->id,
                    'schedule_id' => $schedule->id,
                    'event' => $event,
                ]);
                return;
            }

            // Update summary
            $this->summaryService->updateSummary(
                $attendance->school_id,
                $classId,
                $attendance->attendance_date
            );

            Log::debug('Attendance summary updated via observer', [
                'event' => $event,
                'attendance_id' => $attendance->id,
                'school_id' => $attendance->school_id,
                'class_id' => $classId,
                'date' => $attendance->attendance_date->toDateString(),
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the attendance operation
            Log::error('Failed to update attendance summary', [
                'event' => $event,
                'attendance_id' => $attendance->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}

