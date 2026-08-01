<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\AttendanceSummary;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calculate Attendance Summary Job
 * 
 * Calculates monthly attendance summaries for all students in a school.
 * 
 * TENANT SAFETY:
 * - Extends TenantAwareJob to ensure school_id context
 * - All queries are scoped to the school_id
 * - Should be dispatched separately per school
 * 
 * Usage:
 *   CalculateAttendanceSummary::dispatch($schoolId, $date);
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class CalculateAttendanceSummary extends TenantAwareJob
{
    protected $targetDate;

    /**
     * Create a new job instance.
     *
     * @param int $schoolId The school ID for tenant context (REQUIRED)
     * @param Carbon|null $date Date to calculate summary for (defaults to today)
     */
    public function __construct(int $schoolId, $date = null)
    {
        parent::__construct($schoolId);
        $this->targetDate = $date ? Carbon::parse($date) : now(\App\Models\School::find($this->schoolId)?->timezone ?? config("app.timezone"));
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $year = $this->targetDate->year;
        $month = $this->targetDate->month;

        Log::info("Starting Attendance Summary Calculation for {$year}-{$month}", [
            'school_id' => $this->schoolId,
        ]);

        // ✅ TENANT SAFETY: Process only students from this school
        User::where('school_id', $this->schoolId)
            ->where('role_type', 'student')
            ->chunk(100, function ($students) use ($year, $month) {
                foreach ($students as $student) {
                    // ✅ TENANT SAFETY: Validate student belongs to this school
                    if ($student->school_id !== $this->schoolId) {
                        Log::error("Tenant context violation in CalculateAttendanceSummary", [
                            'job_school_id' => $this->schoolId,
                            'student_school_id' => $student->school_id,
                            'student_id' => $student->id,
                        ]);
                        continue;
                    }

                    $this->calculateStudentSummary($student, $year, $month);
                }
            });

        Log::info("Completed Attendance Summary Calculation for {$year}-{$month}", [
            'school_id' => $this->schoolId,
        ]);
    }

    protected function calculateStudentSummary(User $student, int $year, int $month)
    {
        // ✅ TENANT SAFETY: Aggregate attendance with explicit school_id filter
        $stats = Attendance::where('school_id', $this->schoolId)
            ->where('student_id', $student->id)
            ->whereYear('attendance_date', $year)
            ->whereMonth('attendance_date', $month)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Get current active class for snapshot
        $classId = $student->classStudent->class_id ?? null;

        // Map status to summary columns
        $data = [
            'present' => ($stats['present'] ?? 0),
            'late' => ($stats['late'] ?? 0),
            'sick' => ($stats['sick'] ?? 0),
            'absent' => ($stats['absent'] ?? 0) + ($stats['alpha'] ?? 0), // Merge alpha into absent if needed
            'permit' => ($stats['permit'] ?? 0) + ($stats['excused'] ?? 0),
        ];

        // Update or Create Summary
        AttendanceSummary::updateOrCreate(
            [
                'school_id' => $this->schoolId, // ✅ Explicit school_id
                'student_id' => $student->id,
                'year' => $year,
                'month' => $month,
            ],
            array_merge($data, ['class_id' => $classId])
        );
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CalculateAttendanceSummary job failed', [
            'job' => self::class,
            'school_id' => $this->schoolId,
            'target_date' => $this->targetDate->toDateString(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
