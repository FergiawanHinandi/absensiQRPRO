<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceDailyClassSummary;
use App\Models\ClassModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Attendance Summary Service
 * 
 * Manages the calculation and updating of daily class attendance summaries.
 * Used by AttendanceObserver to keep summaries in sync with attendance changes.
 */
final class AttendanceSummaryService
{
    /**
     * Update summary for a specific school, class, and date.
     * 
     * This method recalculates the entire summary from scratch to ensure accuracy.
     * It's idempotent - can be called multiple times safely.
     * 
     * @param int $schoolId
     * @param int $classId
     * @param string|Carbon $date
     * @return AttendanceDailyClassSummary
     */
    public function updateSummary(int $schoolId, int $classId, string|Carbon $date): AttendanceDailyClassSummary
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return DB::transaction(function () use ($schoolId, $classId, $dateString) {
            // Calculate counts from actual attendance records
            $counts = $this->calculateCounts($schoolId, $classId, $dateString);

            // Get total active students in class
            $totalStudents = $this->getTotalStudents($classId, $dateString);

            // Calculate alpha (no-show) count
            $attendedCount = array_sum([
                $counts['present'] ?? 0,
                $counts['late'] ?? 0,
                $counts['sick'] ?? 0,
                $counts['permit'] ?? 0,
                $counts['excused'] ?? 0,
                $counts['absent'] ?? 0,
            ]);
            $alphaCount = max(0, $totalStudents - $attendedCount);

            // Update or create summary
            $summary = AttendanceDailyClassSummary::updateOrCreate(
                [
                    'school_id' => $schoolId,
                    'class_id' => $classId,
                    'attendance_date' => $dateString,
                ],
                [
                    'total_students' => $totalStudents,
                    'present_count' => $counts['present'] ?? 0,
                    'late_count' => $counts['late'] ?? 0,
                    'absent_count' => $counts['absent'] ?? 0,
                    'sick_count' => $counts['sick'] ?? 0,
                    'permit_count' => $counts['permit'] ?? 0,
                    'excused_count' => $counts['excused'] ?? 0,
                    'alpha_count' => $alphaCount,
                    'last_updated_at' => now(),
                ]
            );

            Log::info('Attendance summary updated', [
                'school_id' => $schoolId,
                'class_id' => $classId,
                'date' => $dateString,
                'total_students' => $totalStudents,
                'attended' => $attendedCount,
                'alpha' => $alphaCount,
            ]);

            return $summary;
        });
    }

    /**
     * Calculate attendance counts by status for a class on a specific date.
     * 
     * @param int $schoolId
     * @param int $classId
     * @param string $date
     * @return array<string, int>
     */
    protected function calculateCounts(int $schoolId, int $classId, string $date): array
    {
        // Get attendance counts grouped by status
        $results = Attendance::query()
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->where('attendances.school_id', $schoolId)
            ->where('schedules.class_id', $classId)
            ->whereDate('attendances.attendance_date', $date)
            ->groupBy('attendances.status')
            ->select('attendances.status', DB::raw('COUNT(DISTINCT attendances.student_id) as count'))
            ->get();

        // Convert to associative array
        $counts = [];
        foreach ($results as $result) {
            $counts[$result->status] = (int) $result->count;
        }

        return $counts;
    }

    /**
     * Get total active students in a class on a specific date.
     * 
     * @param int $classId
     * @param string $date
     * @return int
     */
    protected function getTotalStudents(int $classId, string $date): int
    {
        return DB::table('class_students')
            ->where('class_id', $classId)
            ->where('status', 'active')
            ->count();
    }

    /**
     * Batch update summaries for multiple classes on a specific date.
     * 
     * Useful for backfilling or recalculating summaries.
     * 
     * @param int $schoolId
     * @param string|Carbon $date
     * @return int Number of summaries updated
     */
    public function updateSummariesForDate(int $schoolId, string|Carbon $date): int
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        // Get all classes with attendance on this date
        $classIds = Attendance::query()
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->where('attendances.school_id', $schoolId)
            ->whereDate('attendances.attendance_date', $dateString)
            ->distinct()
            ->pluck('schedules.class_id');

        $count = 0;
        foreach ($classIds as $classId) {
            $this->updateSummary($schoolId, $classId, $dateString);
            $count++;
        }

        return $count;
    }

    /**
     * Batch update summaries for a date range.
     * 
     * @param int $schoolId
     * @param string|Carbon $startDate
     * @param string|Carbon $endDate
     * @return int Number of summaries updated
     */
    public function updateSummariesForDateRange(int $schoolId, string|Carbon $startDate, string|Carbon $endDate): int
    {
        $start = $startDate instanceof Carbon ? $startDate : Carbon::parse($startDate);
        $end = $endDate instanceof Carbon ? $endDate : Carbon::parse($endDate);

        $totalCount = 0;
        $currentDate = $start->copy();

        while ($currentDate->lte($end)) {
            $count = $this->updateSummariesForDate($schoolId, $currentDate);
            $totalCount += $count;
            $currentDate->addDay();
        }

        Log::info('Batch summary update completed', [
            'school_id' => $schoolId,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_summaries' => $totalCount,
        ]);

        return $totalCount;
    }

    /**
     * Validate summary accuracy by comparing with raw attendance data.
     * 
     * @param int $schoolId
     * @param int $classId
     * @param string|Carbon $date
     * @return array{accurate: bool, summary: array, actual: array, differences: array}
     */
    public function validateSummary(int $schoolId, int $classId, string|Carbon $date): array
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        // Get summary
        $summary = AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('attendance_date', $dateString)
            ->first();

        if (!$summary) {
            return [
                'accurate' => false,
                'summary' => null,
                'actual' => null,
                'differences' => ['Summary does not exist'],
            ];
        }

        // Calculate actual counts
        $actualCounts = $this->calculateCounts($schoolId, $classId, $dateString);
        $actualTotal = $this->getTotalStudents($classId, $dateString);
        $actualAttended = array_sum($actualCounts);
        $actualAlpha = max(0, $actualTotal - $actualAttended);

        // Compare
        $differences = [];
        
        if ($summary->total_students !== $actualTotal) {
            $differences[] = "total_students: {$summary->total_students} vs {$actualTotal}";
        }
        if ($summary->present_count !== ($actualCounts['present'] ?? 0)) {
            $differences[] = "present_count: {$summary->present_count} vs " . ($actualCounts['present'] ?? 0);
        }
        if ($summary->late_count !== ($actualCounts['late'] ?? 0)) {
            $differences[] = "late_count: {$summary->late_count} vs " . ($actualCounts['late'] ?? 0);
        }
        if ($summary->absent_count !== ($actualCounts['absent'] ?? 0)) {
            $differences[] = "absent_count: {$summary->absent_count} vs " . ($actualCounts['absent'] ?? 0);
        }
        if ($summary->sick_count !== ($actualCounts['sick'] ?? 0)) {
            $differences[] = "sick_count: {$summary->sick_count} vs " . ($actualCounts['sick'] ?? 0);
        }
        if ($summary->permit_count !== ($actualCounts['permit'] ?? 0)) {
            $differences[] = "permit_count: {$summary->permit_count} vs " . ($actualCounts['permit'] ?? 0);
        }
        if ($summary->excused_count !== ($actualCounts['excused'] ?? 0)) {
            $differences[] = "excused_count: {$summary->excused_count} vs " . ($actualCounts['excused'] ?? 0);
        }
        if ($summary->alpha_count !== $actualAlpha) {
            $differences[] = "alpha_count: {$summary->alpha_count} vs {$actualAlpha}";
        }

        return [
            'accurate' => empty($differences),
            'summary' => [
                'total_students' => $summary->total_students,
                'present' => $summary->present_count,
                'late' => $summary->late_count,
                'absent' => $summary->absent_count,
                'sick' => $summary->sick_count,
                'permit' => $summary->permit_count,
                'excused' => $summary->excused_count,
                'alpha' => $summary->alpha_count,
            ],
            'actual' => [
                'total_students' => $actualTotal,
                'present' => $actualCounts['present'] ?? 0,
                'late' => $actualCounts['late'] ?? 0,
                'absent' => $actualCounts['absent'] ?? 0,
                'sick' => $actualCounts['sick'] ?? 0,
                'permit' => $actualCounts['permit'] ?? 0,
                'excused' => $actualCounts['excused'] ?? 0,
                'alpha' => $actualAlpha,
            ],
            'differences' => $differences,
        ];
    }

    /**
     * Delete summary for a specific class and date.
     * 
     * @param int $schoolId
     * @param int $classId
     * @param string|Carbon $date
     * @return bool
     */
    public function deleteSummary(int $schoolId, int $classId, string|Carbon $date): bool
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->where('class_id', $classId)
            ->where('attendance_date', $dateString)
            ->delete() > 0;
    }

    /**
     * Get summary for dashboard display.
     * 
     * @param int $schoolId
     * @param string|Carbon $date
     * @return \Illuminate\Support\Collection
     */
    public function getSummariesForDashboard(int $schoolId, string|Carbon $date)
    {
        $dateString = $date instanceof Carbon ? $date->toDateString() : $date;

        return AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->where('attendance_date', $dateString)
            ->with('class:id,name,grade_level')
            ->orderBy('class_id')
            ->get();
    }
}

