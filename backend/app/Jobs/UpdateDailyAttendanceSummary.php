<?php

namespace App\Jobs;

use App\Events\AttendanceRecorded;
use App\Models\DailyAttendanceSummary;
use Illuminate\Support\Facades\Log;

/**
 * Update Daily Attendance Summary Job
 * 
 * Updates daily attendance summary counters when attendance is recorded.
 * 
 * TENANT SAFETY:
 * - Extends TenantAwareJob to ensure school_id context
 * - Validates attendance belongs to the correct school
 * - All queries are scoped to the school_id
 * 
 * Usage:
 *   UpdateDailyAttendanceSummary::dispatch($schoolId, $event);
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class UpdateDailyAttendanceSummary extends TenantAwareJob
{
    public $event;

    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     *
     * @param int $schoolId The school ID for tenant context (REQUIRED)
     * @param AttendanceRecorded $event The attendance recorded event
     */
    public function __construct(int $schoolId, AttendanceRecorded $event)
    {
        parent::__construct($schoolId);
        $this->event = $event;
    }

    public function handle(): void
    {
        $attendance = $this->event->attendance;

        // ✅ TENANT SAFETY: Validate attendance belongs to this school
        if ($attendance->school_id !== $this->schoolId) {
            Log::error("Tenant context violation in UpdateDailyAttendanceSummary", [
                'job_school_id' => $this->schoolId,
                'attendance_school_id' => $attendance->school_id,
                'attendance_id' => $attendance->id,
            ]);
            return;
        }

        $schoolId = $attendance->school_id;
        $date = $attendance->attendance_date;
        $status = $attendance->status;

        // Map status to counter column
        $columnMap = [
            'present' => 'total_present',
            'late' => 'total_late',
            'absent' => 'total_absent',
            'alpha' => 'total_absent',
            'permission' => 'total_permission',
            'excused' => 'total_excused',
            'sick' => 'total_sick',
        ];

        $targetColumn = $columnMap[$status] ?? null;

        if (! $targetColumn) {
            Log::warning("Unknown status '{$status}' for attendance summary update.", [
                'school_id' => $this->schoolId,
            ]);
            return;
        }

        // Use Eloquent updateOrCreate — DB-agnostic, works on PostgreSQL & MySQL
        try {
            // ✅ TENANT SAFETY: Explicit school_id filter
            $summary = DailyAttendanceSummary::firstOrNew([
                'school_id' => $schoolId,
                'attendance_date' => $date,
                'class_id' => $attendance->class_id,
            ]);

            $summary->{$targetColumn} = ($summary->{$targetColumn} ?? 0) + 1;
            $summary->last_updated_at = now(\App\Models\School::find($this->schoolId)?->timezone ?? config("app.timezone"));
            $summary->save();

        } catch (\Exception $e) {
            Log::error('Failed to update daily summary: '.$e->getMessage(), [
                'school_id' => $schoolId,
                'date' => $date,
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('UpdateDailyAttendanceSummary job failed', [
            'job' => self::class,
            'school_id' => $this->schoolId,
            'error' => $exception->getMessage(),
        ]);
    }
}
