<?php

declare(strict_types=1);

namespace App\ReadModels\Projectors;

use App\Models\Attendance;
use App\ReadModels\AttendanceDailySummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Attendance Summary Projector
 * 
 * This projector updates the READ MODEL (AttendanceDailySummary)
 * based on changes in the WRITE MODEL (Attendance).
 * 
 * This is the bridge between WRITE and READ sides in CQRS.
 * 
 * Responsibilities:
 * - Aggregate attendance data into daily summaries
 * - Update read models when attendance changes
 * - Maintain eventual consistency
 * 
 * Called by event listeners when attendance events occur.
 * 
 * @package App\ReadModels\Projectors
 */
class AttendanceSummaryProjector
{
    /**
     * Project attendance data for a specific school and date
     * 
     * This recalculates the entire summary for the given school/date
     * by aggregating all attendance records.
     * 
     * @param int $schoolId
     * @param Carbon|string $date
     * @param int|null $classId Optional: project for specific class
     * @return AttendanceDailySummary
     */
    public function projectForDate(int $schoolId, $date, ?int $classId = null): AttendanceDailySummary
    {
        $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        // Aggregate attendance data
        $stats = $this->aggregateAttendanceStats($schoolId, $dateStr, $classId);

        // Upsert summary
        return $this->upsertSummary($schoolId, $dateStr, $classId, $stats);
    }

    /**
     * Project summaries for all classes on a specific date
     * 
     * @param int $schoolId
     * @param Carbon|string $date
     * @return void
     */
    public function projectAllClassesForDate(int $schoolId, $date): void
    {
        $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : $date;

        // Project school-wide summary
        $this->projectForDate($schoolId, $dateStr, null);

        // Get all classes with attendance on this date
        $classIds = Attendance::where('school_id', $schoolId)
            ->where('attendance_date', $dateStr)
            ->whereNotNull('class_id')
            ->distinct()
            ->pluck('class_id');

        // Project summary for each class
        foreach ($classIds as $classId) {
            $this->projectForDate($schoolId, $dateStr, $classId);
        }
    }

    /**
     * Aggregate attendance statistics from raw attendance records
     * 
     * @param int $schoolId
     * @param string $date
     * @param int|null $classId
     * @return array
     */
    private function aggregateAttendanceStats(int $schoolId, string $date, ?int $classId = null): array
    {
        $query = Attendance::where('school_id', $schoolId)
            ->where('attendance_date', $date);

        if ($classId !== null) {
            $query->where('class_id', $classId);
        }

        // Count by status
        $statusCounts = $query->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $totalPresent = ($statusCounts['present'] ?? 0) + ($statusCounts['hadir'] ?? 0);
        $totalLate = ($statusCounts['late'] ?? 0) + ($statusCounts['terlambat'] ?? 0);
        $totalAbsent = ($statusCounts['absent'] ?? 0) + ($statusCounts['alpha'] ?? 0);
        $totalExcused = ($statusCounts['excused'] ?? 0) + ($statusCounts['izin'] ?? 0) + ($statusCounts['sakit'] ?? 0);

        $totalStudents = $totalPresent + $totalLate + $totalAbsent + $totalExcused;
        
        $attendanceRate = $totalStudents > 0 
            ? round((($totalPresent + $totalLate) / $totalStudents) * 100, 2)
            : 0;

        return [
            'total_students' => $totalStudents,
            'total_present' => $totalPresent,
            'total_late' => $totalLate,
            'total_absent' => $totalAbsent,
            'total_excused' => $totalExcused,
            'attendance_rate' => $attendanceRate,
        ];
    }

    /**
     * Upsert (update or insert) the summary record
     * 
     * @param int $schoolId
     * @param string $date
     * @param int|null $classId
     * @param array $stats
     * @return AttendanceDailySummary
     */
    private function upsertSummary(int $schoolId, string $date, ?int $classId, array $stats): AttendanceDailySummary
    {
        $attributes = [
            'school_id' => $schoolId,
            'attendance_date' => $date,
            'class_id' => $classId,
        ];

        $values = array_merge($stats, [
            'last_updated_at' => now(),
        ]);

        try {
            return AttendanceDailySummary::updateOrCreate($attributes, $values);
        } catch (\Exception $e) {
            Log::error('Failed to upsert attendance summary', [
                'school_id' => $schoolId,
                'date' => $date,
                'class_id' => $classId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Rebuild all summaries for a school (maintenance operation)
     * 
     * @param int $schoolId
     * @param Carbon|null $startDate
     * @param Carbon|null $endDate
     * @return int Number of summaries rebuilt
     */
    public function rebuildForSchool(int $schoolId, ?Carbon $startDate = null, ?Carbon $endDate = null): int
    {
        $startDate = $startDate ?? now()->subDays(90);
        $endDate = $endDate ?? now();

        $dates = Attendance::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->distinct()
            ->pluck('attendance_date');

        $count = 0;
        foreach ($dates as $date) {
            $this->projectAllClassesForDate($schoolId, $date);
            $count++;
        }

        Log::info('Rebuilt attendance summaries', [
            'school_id' => $schoolId,
            'dates_processed' => $count,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
        ]);

        return $count;
    }
}
