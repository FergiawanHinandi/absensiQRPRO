<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\StudentAttendanceRisk;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Calculate Attendance Risk Job
 * 
 * Calculates attendance risk scores for students in a specific school.
 * Analyzes attendance patterns over 60 days and identifies risky weekdays.
 * 
 * TENANT SAFETY:
 * - Extends TenantAwareJob to ensure school_id context
 * - All queries are scoped to the school_id
 * - Should be dispatched separately per school
 * 
 * Usage:
 *   CalculateAttendanceRisk::dispatch($schoolId);
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class CalculateAttendanceRisk extends TenantAwareJob
{
    /**
     * Create a new job instance.
     *
     * @param int $schoolId The school ID for tenant context (REQUIRED)
     */
    public function __construct(int $schoolId)
    {
        parent::__construct($schoolId);
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Starting CalculateAttendanceRisk job...', [
            'school_id' => $this->schoolId,
        ]);

        // ✅ TENANT SAFETY: Get Active Students with explicit school_id filter
        $students = User::where('school_id', $this->schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->with(['attendances' => function ($query) {
                // Fetch last 60 days of attendance
                $query->where('attendance_date', '>=', Carbon::now(\App\Models\School::find($this->schoolId)?->timezone ?? config('app.timezone'))->subDays(60))
                    ->where('school_id', $this->schoolId); // ✅ Explicit school_id filter
            }])
            ->get();

        Log::info("Processing risk for {$students->count()} active students.", [
            'school_id' => $this->schoolId,
        ]);

        foreach ($students as $student) {
            try {
                // ✅ TENANT SAFETY: Validate student belongs to this school
                if ($student->school_id !== $this->schoolId) {
                    Log::error("Tenant context violation in CalculateAttendanceRisk", [
                        'job_school_id' => $this->schoolId,
                        'student_school_id' => $student->school_id,
                        'student_id' => $student->id,
                    ]);
                    continue;
                }

                $this->calculateRiskForStudent($student);
            } catch (\Exception $e) {
                Log::error("Failed to calculate risk for student ID {$student->id}: ".$e->getMessage(), [
                    'school_id' => $this->schoolId,
                ]);
            }
        }

        Log::info('CalculateAttendanceRisk job completed.', [
            'school_id' => $this->schoolId,
        ]);
    }

    private function calculateRiskForStudent(User $student)
    {
        // Filter attendances for score calculation (last 30 days)
        $attendances30Days = $student->attendances->where('attendance_date', '>=', Carbon::now(\App\Models\School::find($this->schoolId)?->timezone ?? config('app.timezone'))->subDays(30));

        // Full 60 days for pattern analysis
        $attendances60Days = $student->attendances;

        $absentCount = $attendances30Days->where('status', 'absent')->count();
        $lateCount = $attendances30Days->where('status', 'late')->count();
        $presentCount = $attendances30Days->where('status', 'present')->count();
        $totalRecords = $attendances30Days->count();

        // Calculate Score (Based on last 30 days)
        // Weight: Absent=10, Late=3
        $score = ($absentCount * 10) + ($lateCount * 3);

        // Additional Factor: Low Attendance Rate (if records exist)
        // If present rate < 50%, add 20 points penalty
        if ($totalRecords > 0) {
            $presentRate = ($presentCount / $totalRecords) * 100;
            if ($presentRate < 50) {
                $score += 20;
            } elseif ($presentRate < 75) {
                $score += 10;
            }
        }

        // --- Weekly Pattern Analysis (Last 60 Days) ---
        $weekdayAbsences = [
            'Sunday' => 0, 'Monday' => 0, 'Tuesday' => 0, 'Wednesday' => 0, 'Thursday' => 0, 'Friday' => 0, 'Saturday' => 0,
        ];

        $absences60Days = $attendances60Days->where('status', 'absent');

        foreach ($absences60Days as $attendance) {
            // attendance_date is a date string or Carbon object?
            // If it's casted to date in model, it's Carbon. If string, need Carbon::parse.
            // Assuming casted or string.
            $date = Carbon::parse($attendance->attendance_date);
            $dayName = $date->format('l'); // Monday, Tuesday...
            if (isset($weekdayAbsences[$dayName])) {
                $weekdayAbsences[$dayName]++;
            }
        }

        // Identify High Risk Day (Weekday with most absences in 60 days)
        $maxAbsences = 0;
        $riskyDayCandidate = null;

        foreach ($weekdayAbsences as $day => $count) {
            if ($count > $maxAbsences) {
                $maxAbsences = $count;
                $riskyDayCandidate = $day;
            }
        }

        // FLAG if: Same weekday absent >= 3 times in a month (using last 30 days check for the flag trigger)
        $riskyWeekday = null;
        if ($riskyDayCandidate) {
            $absencesOnRiskyDayLast30Days = $attendances30Days
                ->where('status', 'absent')
                ->filter(function ($att) use ($riskyDayCandidate) {
                    return Carbon::parse($att->attendance_date)->format('l') === $riskyDayCandidate;
                })
                ->count();

            if ($absencesOnRiskyDayLast30Days >= 3) {
                $riskyWeekday = $riskyDayCandidate;
                // Increase score if a pattern is detected
                $score += 5;
            }
        }

        // Determine Level
        $level = 'low';
        if ($score >= 50) {
            $level = 'high';
        } elseif ($score >= 30) {
            $level = 'high'; // Or medium-high? sticking to low/medium/high
        } elseif ($score >= 10) {
            $level = 'medium';
        }

        // Factors for JSON
        $factors = [
            'absences_last_30_days' => $absentCount,
            'lates_last_30_days' => $lateCount,
            'attendance_rate' => $totalRecords > 0 ? round(($presentCount / $totalRecords) * 100, 1) : 0,
            'total_records_analyzed' => $totalRecords,
            'risky_weekday_detected' => $riskyWeekday,
        ];

        // Fetch existing risk profile to compare
        $existingProfile = StudentAttendanceRisk::where('student_id', $student->id)->first();
        $oldLevel = $existingProfile ? $existingProfile->risk_level : 'low';

        // Update or Insert
        $riskProfile = StudentAttendanceRisk::updateOrCreate(
            ['student_id' => $student->id],
            [
                'risk_score' => $score,
                'risk_level' => $level,
                'risky_weekday' => $riskyWeekday,
                'factors_json' => $factors,
                'calculated_at' => now(),
            ]
        );

        // Dispatch Event if Risk Level Increases
        $levels = ['low' => 1, 'medium' => 2, 'high' => 3];
        $oldRank = $levels[$oldLevel] ?? 1;
        $newRank = $levels[$level] ?? 1;

        if ($newRank > $oldRank) {
            // RiskLevelUpdated::dispatch($riskProfile);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CalculateAttendanceRisk job failed', [
            'job' => self::class,
            'school_id' => $this->schoolId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
