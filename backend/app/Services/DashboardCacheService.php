<?php

namespace App\Services;

use App\Models\Attendance;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Cache Service
 * 
 * Centralized caching for dashboard data with cache stampede protection
 * 
 * CACHE STRATEGY:
 * - TTL: 60 minutes for dashboard stats
 * - TTL: 5 minutes for real-time data
 * - Cache tags for easy invalidation
 * - Cache lock pattern to prevent stampede
 * 
 * CACHE INVALIDATION:
 * - On new attendance record
 * - On attendance update/delete
 * - Manual flush via admin
 * 
 * CACHE STAMPEDE PROTECTION:
 * - Uses CacheLockService to prevent concurrent regeneration
 * - Only one process regenerates cache at a time
 * - Other processes wait and retry with exponential backoff
 * 
 * @version 2.0.0
 */
class DashboardCacheService
{
    /**
     * Cache TTL in seconds
     */
    const CACHE_TTL_DASHBOARD = 3600; // 60 minutes
    const CACHE_TTL_REALTIME = 300;   // 5 minutes
    const CACHE_TTL_REPORTS = 1800;   // 30 minutes

    /**
     * Cache lock service
     */
    protected CacheLockService $cacheLock;

    /**
     * Constructor
     */
    public function __construct(CacheLockService $cacheLock)
    {
        $this->cacheLock = $cacheLock;
    }

    /**
     * Get dashboard data for school (with caching and stampede protection)
     * 
     * @param int $schoolId
     * @param string $date
     * @return array
     */
    public function getDashboardData(int $schoolId, string $date = null): array
    {
        $date = $date ?? Carbon::today()->toDateString();
        $cacheKey = "dashboard_{$schoolId}_{$date}";

        return $this->cacheLock->remember(
            $cacheKey,
            self::CACHE_TTL_DASHBOARD,
            fn() => $this->fetchDashboardData($schoolId, $date)
        );
    }

    /**
     * Get real-time attendance stats (shorter cache with stampede protection)
     * 
     * @param int $schoolId
     * @return array
     */
    public function getRealtimeStats(int $schoolId): array
    {
        $cacheKey = "realtime_stats_{$schoolId}";

        return $this->cacheLock->remember(
            $cacheKey,
            self::CACHE_TTL_REALTIME,
            function () use ($schoolId) {
                $today = Carbon::today()->toDateString();
                
                return Attendance::where('school_id', $schoolId)
                    ->whereDate('attendance_date', $today)
                    ->selectRaw("
                        COUNT(DISTINCT student_id) as total_students,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                        MAX(created_at) as last_updated
                    ")
                    ->first()
                    ->toArray();
            }
        );
    }

    /**
     * Get monthly summary (with caching and stampede protection)
     * 
     * @param int $schoolId
     * @param int $month
     * @param int $year
     * @return array
     */
    public function getMonthlySummary(int $schoolId, int $month, int $year): array
    {
        $cacheKey = "monthly_summary_{$schoolId}_{$month}_{$year}";

        return $this->cacheLock->remember(
            $cacheKey,
            self::CACHE_TTL_REPORTS,
            fn() => $this->fetchMonthlySummary($schoolId, $month, $year)
        );
    }

    /**
     * Get class attendance summary (with caching and stampede protection)
     * 
     * @param int $classId
     * @param string $date
     * @return array
     */
    public function getClassSummary(int $classId, string $date): array
    {
        $cacheKey = "class_summary_{$classId}_{$date}";

        return $this->cacheLock->remember(
            $cacheKey,
            self::CACHE_TTL_DASHBOARD,
            function () use ($classId, $date) {
                return Attendance::where('class_id', $classId)
                    ->whereDate('attendance_date', $date)
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                        ROUND(((SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) + 
                                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END))::numeric / 
                                NULLIF(COUNT(*), 0)) * 100, 2) as attendance_rate
                    ")
                    ->first()
                    ->toArray();
            }
        );
    }

    /**
     * Invalidate dashboard cache for school
     * 
     * Call this when:
     * - New attendance record created
     * - Attendance record updated/deleted
     * 
     * @param int $schoolId
     * @param string|null $date
     */
    public function invalidateDashboard(int $schoolId, string $date = null): void
    {
        $date = $date ?? Carbon::today()->toDateString();
        
        // Clear specific date cache
        Cache::forget("dashboard_{$schoolId}_{$date}");
        Cache::forget("realtime_stats_{$schoolId}");
        
        // Clear all dashboard caches for this school (if using tags)
        if (method_exists(Cache::getStore(), 'tags')) {
            Cache::tags(["school_{$schoolId}", 'dashboard'])->flush();
        }
    }

    /**
     * Invalidate class cache
     * 
     * @param int $classId
     * @param string|null $date
     */
    public function invalidateClass(int $classId, string $date = null): void
    {
        $date = $date ?? Carbon::today()->toDateString();
        Cache::forget("class_summary_{$classId}_{$date}");
        
        // Also invalidate monthly cache for this class
        $month = Carbon::parse($date)->month;
        $year = Carbon::parse($date)->year;
        Cache::forget("monthly_attendance_{$classId}_{$month}_{$year}_v3");
    }

    /**
     * Invalidate student cache
     * 
     * @param int $studentId
     * @param string|null $date
     */
    public function invalidateStudent(int $studentId, string $date = null): void
    {
        $date = $date ?? Carbon::today()->toDateString();
        $month = Carbon::parse($date)->month;
        $year = Carbon::parse($date)->year;
        
        // Clear student monthly report cache
        Cache::forget("student_monthly_{$studentId}_{$month}_{$year}");
    }

    /**
     * Invalidate monthly summary cache
     * 
     * @param int $schoolId
     * @param int|null $month
     * @param int|null $year
     */
    public function invalidateMonthlySummary(int $schoolId, int $month = null, int $year = null): void
    {
        $month = $month ?? Carbon::now()->month;
        $year = $year ?? Carbon::now()->year;
        
        Cache::forget("monthly_summary_{$schoolId}_{$month}_{$year}");
    }

    /**
     * Flush all dashboard caches (admin only)
     * 
     * @param int|null $schoolId If null, flush all schools
     */
    public function flushAll(int $schoolId = null): void
    {
        if ($schoolId) {
            // Flush specific school
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(["school_{$schoolId}"])->flush();
            } else {
                // Fallback: clear common patterns
                $patterns = [
                    "dashboard_{$schoolId}_*",
                    "realtime_stats_{$schoolId}",
                    "monthly_summary_{$schoolId}_*",
                ];
                
                foreach ($patterns as $pattern) {
                    // Note: This requires Redis or similar cache driver
                    // For file cache, you may need different approach
                }
            }
        } else {
            // Flush all dashboard caches
            if (method_exists(Cache::getStore(), 'tags')) {
                Cache::tags(['dashboard'])->flush();
            } else {
                Cache::flush(); // Nuclear option
            }
        }
    }

    /**
     * Fetch dashboard data from database
     * 
     * @param int $schoolId
     * @param string $date
     * @return array
     */
    protected function fetchDashboardData(int $schoolId, string $date): array
    {
        // Today's stats
        $todayStats = Attendance::where('school_id', $schoolId)
            ->whereDate('attendance_date', $date)
            ->selectRaw("
                COUNT(DISTINCT student_id) as total_students,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
                SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit
            ")
            ->first();

        // Per-class breakdown
        $classSummary = DB::table('classes')
            ->leftJoin(DB::raw("(
                SELECT class_id,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
                FROM attendances
                WHERE school_id = {$schoolId}
                    AND DATE(attendance_date) = '{$date}'
                GROUP BY class_id
            ) as att"), 'classes.id', '=', 'att.class_id')
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->select([
                'classes.id',
                'classes.name',
                'classes.grade_level',
                DB::raw('COALESCE(att.total, 0) as total'),
                DB::raw('COALESCE(att.present, 0) as present'),
                DB::raw('COALESCE(att.late, 0) as late'),
                DB::raw('CASE
                    WHEN COALESCE(att.total, 0) > 0
                    THEN ROUND(((COALESCE(att.present, 0) + COALESCE(att.late, 0))::numeric / COALESCE(att.total, 0)) * 100, 2)
                    ELSE 0
                END as attendance_rate')
            ])
            ->orderBy('classes.grade_level')
            ->orderBy('classes.name')
            ->get();

        $totalStudents = (int) ($todayStats->total_students ?? 0);
        $present = (int) ($todayStats->present ?? 0);
        $late = (int) ($todayStats->late ?? 0);
        $overallRate = $totalStudents > 0 ? round((($present + $late) / $totalStudents) * 100, 2) : 0;

        return [
            'today' => [
                'date' => $date,
                'total_students' => $totalStudents,
                'present' => $present,
                'late' => $late,
                'absent' => (int) ($todayStats->absent ?? 0),
                'sick' => (int) ($todayStats->sick ?? 0),
                'permit' => (int) ($todayStats->permit ?? 0),
                'attendance_rate' => $overallRate,
            ],
            'classes' => $classSummary,
            'cached_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Fetch monthly summary from database
     * 
     * @param int $schoolId
     * @param int $month
     * @param int $year
     * @return array
     */
    protected function fetchMonthlySummary(int $schoolId, int $month, int $year): array
    {
        $stats = Attendance::where('school_id', $schoolId)
            ->whereMonth('attendance_date', $month)
            ->whereYear('attendance_date', $year)
            ->selectRaw("
                COUNT(DISTINCT attendance_date) as school_days,
                COUNT(DISTINCT student_id) as total_students,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
            ")
            ->first();

        $schoolDays = (int) ($stats->school_days ?? 0);
        $totalStudents = (int) ($stats->total_students ?? 0);
        $present = (int) ($stats->present ?? 0);
        $late = (int) ($stats->late ?? 0);
        
        $avgRate = $schoolDays > 0 && $totalStudents > 0
            ? round((($present + $late) / ($schoolDays * $totalStudents)) * 100, 2)
            : 0;

        return [
            'month' => $month,
            'year' => $year,
            'school_days' => $schoolDays,
            'avg_attendance_rate' => $avgRate,
            'total_present' => $present,
            'total_late' => $late,
            'total_absent' => (int) ($stats->absent ?? 0),
            'cached_at' => now()->toIso8601String(),
        ];
    }
}
