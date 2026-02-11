<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\ReadModels\AttendanceDailySummary;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Dashboard Query Service
 * 
 * This is the APPLICATION LAYER service for READING attendance data.
 * It encapsulates all queries against READ MODELS (CQRS Query Side).
 * 
 * Responsibilities:
 * - Query pre-aggregated read models
 * - Provide caching for frequently accessed data
 * - Return data optimized for dashboard display
 * - No business logic - pure data retrieval
 * 
 * This service uses READ MODELS (AttendanceDailySummary)
 * which are eventually consistent and optimized for queries.
 * 
 * @package App\Application\Services
 */
class DashboardQueryService
{
    /**
     * Get today's attendance summary for a school
     * 
     * @param int $schoolId
     * @return AttendanceDailySummary|null
     */
    public function getTodaySummary(int $schoolId): ?AttendanceDailySummary
    {
        $cacheKey = "dashboard:school:{$schoolId}:today";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addMinutes(5), function () use ($schoolId) {
                return AttendanceDailySummary::getTodaySummary($schoolId);
            });
    }

    /**
     * Get attendance summary for a specific date
     * 
     * @param int $schoolId
     * @param Carbon|string $date
     * @return AttendanceDailySummary|null
     */
    public function getSummaryForDate(int $schoolId, $date): ?AttendanceDailySummary
    {
        $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : $date;
        $cacheKey = "dashboard:school:{$schoolId}:date:{$dateStr}";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addHours(1), function () use ($schoolId, $date) {
                return AttendanceDailySummary::forSchool($schoolId)
                    ->forDate($date)
                    ->schoolWide()
                    ->first();
            });
    }

    /**
     * Get weekly attendance trend
     * 
     * @param int $schoolId
     * @param int $days Number of days to look back (default: 7)
     * @return Collection
     */
    public function getWeeklyTrend(int $schoolId, int $days = 7): Collection
    {
        $cacheKey = "dashboard:school:{$schoolId}:weekly:{$days}";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addMinutes(15), function () use ($schoolId, $days) {
                return AttendanceDailySummary::getWeeklyTrend($schoolId, $days);
            });
    }

    /**
     * Get class-level summaries for a specific date
     * 
     * @param int $schoolId
     * @param Carbon|string $date
     * @return Collection
     */
    public function getClassSummaries(int $schoolId, $date): Collection
    {
        $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : $date;
        $cacheKey = "dashboard:school:{$schoolId}:classes:{$dateStr}";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addMinutes(10), function () use ($schoolId, $date) {
                return AttendanceDailySummary::getClassSummaries($schoolId, $date);
            });
    }

    /**
     * Get summary for a specific class and date
     * 
     * @param int $schoolId
     * @param int $classId
     * @param Carbon|string $date
     * @return AttendanceDailySummary|null
     */
    public function getClassSummary(int $schoolId, int $classId, $date): ?AttendanceDailySummary
    {
        $dateStr = $date instanceof Carbon ? $date->format('Y-m-d') : $date;
        $cacheKey = "dashboard:school:{$schoolId}:class:{$classId}:date:{$dateStr}";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addMinutes(10), function () use ($schoolId, $classId, $date) {
                return AttendanceDailySummary::getClassSummary($schoolId, $classId, $date);
            });
    }

    /**
     * Get monthly statistics
     * 
     * @param int $schoolId
     * @param int $month
     * @param int $year
     * @return array
     */
    public function getMonthlyStatistics(int $schoolId, int $month, int $year): array
    {
        $cacheKey = "dashboard:school:{$schoolId}:monthly:{$year}-{$month}";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addHours(6), function () use ($schoolId, $month, $year) {
                $startDate = Carbon::create($year, $month, 1)->startOfMonth();
                $endDate = $startDate->copy()->endOfMonth();

                $summaries = AttendanceDailySummary::forSchool($schoolId)
                    ->schoolWide()
                    ->dateRange($startDate, $endDate)
                    ->get();

                return [
                    'total_days' => $summaries->count(),
                    'avg_attendance_rate' => $summaries->avg('attendance_rate'),
                    'total_present' => $summaries->sum('total_present'),
                    'total_late' => $summaries->sum('total_late'),
                    'total_absent' => $summaries->sum('total_absent'),
                    'total_excused' => $summaries->sum('total_excused'),
                    'daily_summaries' => $summaries,
                ];
            });
    }

    /**
     * Get attendance rate trend (for charts)
     * 
     * @param int $schoolId
     * @param int $days
     * @return array
     */
    public function getAttendanceRateTrend(int $schoolId, int $days = 30): array
    {
        $cacheKey = "dashboard:school:{$schoolId}:rate-trend:{$days}";
        
        return Cache::tags(['dashboard', "school:{$schoolId}"])
            ->remember($cacheKey, now()->addMinutes(30), function () use ($schoolId, $days) {
                $startDate = today()->subDays($days - 1);
                $endDate = today();

                $summaries = AttendanceDailySummary::forSchool($schoolId)
                    ->schoolWide()
                    ->dateRange($startDate, $endDate)
                    ->orderBy('attendance_date')
                    ->get();

                return [
                    'labels' => $summaries->pluck('attendance_date')->map(fn($date) => $date->format('M d'))->toArray(),
                    'attendance_rates' => $summaries->pluck('attendance_rate')->toArray(),
                    'total_present' => $summaries->pluck('total_present')->toArray(),
                    'total_late' => $summaries->pluck('total_late')->toArray(),
                    'total_absent' => $summaries->pluck('total_absent')->toArray(),
                ];
            });
    }

    /**
     * Clear cache for a specific school
     * 
     * @param int $schoolId
     * @return void
     */
    public function clearCache(int $schoolId): void
    {
        Cache::tags(["school:{$schoolId}"])->flush();
    }

    /**
     * Clear all dashboard cache
     * 
     * @return void
     */
    public function clearAllCache(): void
    {
        Cache::tags(['dashboard'])->flush();
    }
}
