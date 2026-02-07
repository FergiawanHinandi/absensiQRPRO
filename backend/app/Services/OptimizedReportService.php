<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CRITICAL: Optimized Report Service for High-Performance Queries
 *
 * FEATURES:
 * - Single-query aggregations
 * - Memory-efficient processing
 * - Intelligent caching
 * - Batch processing for large datasets
 */
class OptimizedReportService
{
    /**
     * CRITICAL: Get daily attendance statistics with single query
     */
    public function getDailyAttendanceStats(int $schoolId, string $date): array
    {
        $cacheKey = "daily_stats_{$schoolId}_{$date}";

        return Cache::remember($cacheKey, 600, function () use ($schoolId, $date) {
            // CRITICAL: Single query with multiple aggregations
            $stats = Attendance::selectRaw('
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as present,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as late,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as sick,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as permit,
                COUNT(DISTINCT student_id) as total_attended,
                AVG(CASE WHEN check_in_time IS NOT NULL 
                    THEN EXTRACT(EPOCH FROM check_in_time::time) END) as avg_check_in_time,
                COUNT(CASE WHEN is_manual = true THEN 1 END) as manual_entries
            ', ['present', 'late', 'sick', 'permit'])
                ->where('school_id', $schoolId)
                ->whereDate('attendance_date', $date)
                ->first();

            // CRITICAL: Get total students in single query
            $totalStudents = User::where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->count();

            $totalAttended = $stats->total_attended ?? 0;
            $alpha = max(0, $totalStudents - $totalAttended);

            return [
                'total_students' => $totalStudents,
                'present' => $stats->present ?? 0,
                'late' => $stats->late ?? 0,
                'sick' => $stats->sick ?? 0,
                'permit' => $stats->permit ?? 0,
                'alpha' => $alpha,
                'attendance_rate' => $totalStudents > 0 ? round(($totalAttended / $totalStudents) * 100, 1) : 0,
                'avg_check_in_time' => $stats->avg_check_in_time ? gmdate('H:i', $stats->avg_check_in_time) : null,
                'manual_entries' => $stats->manual_entries ?? 0,
            ];
        });
    }

    /**
     * CRITICAL: Get weekly attendance trends with optimized query
     */
    public function getWeeklyAttendanceTrends(int $schoolId, string $startDate, string $endDate): array
    {
        $cacheKey = "weekly_trends_{$schoolId}_{$startDate}_{$endDate}";

        return Cache::remember($cacheKey, 1800, function () use ($schoolId, $startDate, $endDate) {
            // CRITICAL: Single query for weekly trends
            $trends = Attendance::selectRaw('
                DATE(attendance_date) as date,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as present,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as late,
                COUNT(DISTINCT student_id) as total_attended
            ', ['present', 'late'])
                ->where('school_id', $schoolId)
                ->whereBetween('attendance_date', [$startDate, $endDate])
                ->groupBy('attendance_date')
                ->orderBy('attendance_date')
                ->get();

            return $trends->map(function ($trend) {
                return [
                    'date' => $trend->date,
                    'present' => $trend->present,
                    'late' => $trend->late,
                    'total_attended' => $trend->total_attended,
                    'attendance_rate' => $trend->total_attended > 0 ?
                        round(($trend->present / $trend->total_attended) * 100, 1) : 0,
                ];
            })->toArray();
        });
    }

    /**
     * CRITICAL: Get class-wise attendance summary with optimized joins
     */
    public function getClassAttendanceSummary(int $schoolId, string $date): array
    {
        $cacheKey = "class_summary_{$schoolId}_{$date}";

        return Cache::remember($cacheKey, 900, function () use ($schoolId, $date) {
            // CRITICAL: Optimized query with proper joins
            $summary = DB::table('classes')
                ->leftJoin('class_students', 'classes.id', '=', 'class_students.class_id')
                ->leftJoin('users as students', function ($join) {
                    $join->on('class_students.student_id', '=', 'students.id')
                        ->where('students.is_active', true)
                        ->where('class_students.status', 'active');
                })
                ->leftJoin('attendances', function ($join) use ($date) {
                    $join->on('students.id', '=', 'attendances.student_id')
                        ->whereDate('attendances.attendance_date', $date);
                })
                ->where('classes.school_id', $schoolId)
                ->where('classes.is_active', true)
                ->selectRaw('
                    classes.id,
                    classes.name,
                    classes.grade_level,
                    COUNT(DISTINCT students.id) as total_students,
                    COUNT(DISTINCT CASE WHEN attendances.status = ? THEN attendances.student_id END) as present,
                    COUNT(DISTINCT CASE WHEN attendances.status = ? THEN attendances.student_id END) as late,
                    COUNT(DISTINCT attendances.student_id) as total_attended
                ', ['present', 'late'])
                ->groupBy('classes.id', 'classes.name', 'classes.grade_level')
                ->orderBy('classes.grade_level')
                ->orderBy('classes.name')
                ->get();

            return $summary->map(function ($class) {
                $totalStudents = $class->total_students ?? 0;
                $totalAttended = $class->total_attended ?? 0;
                $alpha = max(0, $totalStudents - $totalAttended);

                return [
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'grade_level' => $class->grade_level,
                    'total_students' => $totalStudents,
                    'present' => $class->present ?? 0,
                    'late' => $class->late ?? 0,
                    'alpha' => $alpha,
                    'attendance_rate' => $totalStudents > 0 ?
                        round(($totalAttended / $totalStudents) * 100, 1) : 0,
                ];
            })->toArray();
        });
    }

    /**
     * CRITICAL: Memory-efficient student attendance export
     */
    public function exportStudentAttendance(int $schoolId, string $startDate, string $endDate)
    {
        // CRITICAL: Use lazy() for memory-efficient processing
        return Attendance::with([
            'student:id,name,username',
            'schedule.subject:id,name',
            'schedule.class:id,name',
        ])
            ->where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->orderBy('attendance_date')
            ->orderBy('student_id')
            ->lazy(500); // Process 500 records at a time
    }

    /**
     * CRITICAL: Batch process attendance statistics
     */
    public function batchProcessAttendanceStats(array $schoolIds, string $date): array
    {
        // CRITICAL: Single query for multiple schools
        $stats = Attendance::selectRaw('
            school_id,
            COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as present,
            COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as late,
            COUNT(DISTINCT student_id) as total_attended
        ', ['present', 'late'])
            ->whereIn('school_id', $schoolIds)
            ->whereDate('attendance_date', $date)
            ->groupBy('school_id')
            ->get()
            ->keyBy('school_id');

        $result = [];
        foreach ($schoolIds as $schoolId) {
            $schoolStats = $stats->get($schoolId);
            $result[$schoolId] = [
                'present' => $schoolStats->present ?? 0,
                'late' => $schoolStats->late ?? 0,
                'total_attended' => $schoolStats->total_attended ?? 0,
            ];
        }

        return $result;
    }

    /**
     * CRITICAL: Clear related caches when attendance data changes
     */
    public function clearAttendanceCaches(int $schoolId, string $date): void
    {
        $patterns = [
            "daily_stats_{$schoolId}_{$date}",
            "class_summary_{$schoolId}_{$date}",
            "weekly_trends_{$schoolId}_*",
            "attendance_daily_report_{$schoolId}_{$date}",
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                // Clear pattern-based cache (implementation depends on cache driver)
                Cache::tags(["school_{$schoolId}", 'attendance'])->flush();
            } else {
                Cache::forget($pattern);
            }
        }
    }
}
