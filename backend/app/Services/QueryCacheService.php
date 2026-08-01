<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * QueryCacheService
 * 
 * Provides intelligent caching for frequently accessed queries
 * to reduce database load and improve response times.
 */
class QueryCacheService
{
    /**
     * Cache TTL configurations (in seconds)
     */
    private const CACHE_TTL = [
        'dashboard_stats' => 300,      // 5 minutes
        'student_list' => 600,         // 10 minutes
        'teacher_list' => 600,         // 10 minutes
        'class_list' => 1800,          // 30 minutes
        'schedule_daily' => 3600,      // 1 hour
        'attendance_summary' => 300,   // 5 minutes
        'school_settings' => 3600,     // 1 hour
    ];

    /**
     * Get or cache dashboard statistics
     */
    public function getDashboardStats(int $schoolId, callable $callback): array
    {
        $cacheKey = "dashboard_stats:{$schoolId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['dashboard_stats'], function () use ($callback, $cacheKey) {
            Log::info('Cache miss for dashboard stats', ['key' => $cacheKey]);
            return $callback();
        });
    }

    /**
     * Get or cache student list
     */
    public function getStudentList(int $schoolId, ?int $classId, callable $callback): array
    {
        $cacheKey = $classId 
            ? "student_list:{$schoolId}:class:{$classId}"
            : "student_list:{$schoolId}:all";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['student_list'], function () use ($callback) {
            return $callback();
        });
    }

    /**
     * Get or cache teacher list
     */
    public function getTeacherList(int $schoolId, callable $callback): array
    {
        $cacheKey = "teacher_list:{$schoolId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['teacher_list'], function () use ($callback) {
            return $callback();
        });
    }

    /**
     * Get or cache class list
     */
    public function getClassList(int $schoolId, callable $callback): array
    {
        $cacheKey = "class_list:{$schoolId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['class_list'], function () use ($callback) {
            return $callback();
        });
    }

    /**
     * Get or cache daily schedule
     */
    public function getDailySchedule(int $schoolId, string $date, callable $callback): array
    {
        $cacheKey = "schedule_daily:{$schoolId}:{$date}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['schedule_daily'], function () use ($callback) {
            return $callback();
        });
    }

    /**
     * Get or cache attendance summary
     */
    public function getAttendanceSummary(int $schoolId, string $date, callable $callback): array
    {
        $cacheKey = "attendance_summary:{$schoolId}:{$date}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['attendance_summary'], function () use ($callback) {
            return $callback();
        });
    }

    /**
     * Get or cache school settings
     */
    public function getSchoolSettings(int $schoolId, callable $callback): array
    {
        $cacheKey = "school_settings:{$schoolId}";
        
        return Cache::remember($cacheKey, self::CACHE_TTL['school_settings'], function () use ($callback) {
            return $callback();
        });
    }

    /**
     * Invalidate dashboard stats cache
     */
    public function invalidateDashboardStats(int $schoolId): void
    {
        Cache::forget("dashboard_stats:{$schoolId}");
        Log::info('Invalidated dashboard stats cache', ['school_id' => $schoolId]);
    }

    /**
     * Invalidate student list cache
     */
    public function invalidateStudentList(int $schoolId, ?int $classId = null): void
    {
        if ($classId) {
            Cache::forget("student_list:{$schoolId}:class:{$classId}");
        } else {
            // Invalidate all student lists for this school
            Cache::forget("student_list:{$schoolId}:all");
            // Note: In production, you might want to use cache tags for better invalidation
        }
        
        Log::info('Invalidated student list cache', [
            'school_id' => $schoolId,
            'class_id' => $classId,
        ]);
    }

    /**
     * Invalidate teacher list cache
     */
    public function invalidateTeacherList(int $schoolId): void
    {
        Cache::forget("teacher_list:{$schoolId}");
        Log::info('Invalidated teacher list cache', ['school_id' => $schoolId]);
    }

    /**
     * Invalidate class list cache
     */
    public function invalidateClassList(int $schoolId): void
    {
        Cache::forget("class_list:{$schoolId}");
        Log::info('Invalidated class list cache', ['school_id' => $schoolId]);
    }

    /**
     * Invalidate daily schedule cache
     */
    public function invalidateDailySchedule(int $schoolId, string $date): void
    {
        Cache::forget("schedule_daily:{$schoolId}:{$date}");
        Log::info('Invalidated daily schedule cache', [
            'school_id' => $schoolId,
            'date' => $date,
        ]);
    }

    /**
     * Invalidate attendance summary cache
     */
    public function invalidateAttendanceSummary(int $schoolId, string $date): void
    {
        Cache::forget("attendance_summary:{$schoolId}:{$date}");
        
        // Also invalidate dashboard stats as it depends on attendance
        $this->invalidateDashboardStats($schoolId);
        
        Log::info('Invalidated attendance summary cache', [
            'school_id' => $schoolId,
            'date' => $date,
        ]);
    }

    /**
     * Invalidate school settings cache
     */
    public function invalidateSchoolSettings(int $schoolId): void
    {
        Cache::forget("school_settings:{$schoolId}");
        Log::info('Invalidated school settings cache', ['school_id' => $schoolId]);
    }

    /**
     * Invalidate all caches for a school
     */
    public function invalidateAllForSchool(int $schoolId): void
    {
        $patterns = [
            "dashboard_stats:{$schoolId}",
            "student_list:{$schoolId}:*",
            "teacher_list:{$schoolId}",
            "class_list:{$schoolId}",
            "schedule_daily:{$schoolId}:*",
            "attendance_summary:{$schoolId}:*",
            "school_settings:{$schoolId}",
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($pattern, '*')) {
                // For patterns with wildcards, we need to flush by prefix
                // This is a simplified approach; in production, use cache tags
                $baseKey = str_replace(':*', '', $pattern);
                Cache::forget($baseKey);
            } else {
                Cache::forget($pattern);
            }
        }

        Log::info('Invalidated all caches for school', ['school_id' => $schoolId]);
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(int $schoolId): array
    {
        $keys = [
            'dashboard_stats' => "dashboard_stats:{$schoolId}",
            'student_list' => "student_list:{$schoolId}:all",
            'teacher_list' => "teacher_list:{$schoolId}",
            'class_list' => "class_list:{$schoolId}",
            'school_settings' => "school_settings:{$schoolId}",
        ];

        $stats = [];
        foreach ($keys as $name => $key) {
            $stats[$name] = [
                'cached' => Cache::has($key),
                'ttl' => self::CACHE_TTL[$name] ?? 0,
            ];
        }

        return $stats;
    }

    /**
     * Warm up cache for a school
     * 
     * Pre-populate frequently accessed caches
     */
    public function warmUpCache(int $schoolId, array $callbacks): void
    {
        Log::info('Warming up cache for school', ['school_id' => $schoolId]);

        if (isset($callbacks['dashboard_stats'])) {
            $this->getDashboardStats($schoolId, $callbacks['dashboard_stats']);
        }

        if (isset($callbacks['student_list'])) {
            $this->getStudentList($schoolId, null, $callbacks['student_list']);
        }

        if (isset($callbacks['teacher_list'])) {
            $this->getTeacherList($schoolId, $callbacks['teacher_list']);
        }

        if (isset($callbacks['class_list'])) {
            $this->getClassList($schoolId, $callbacks['class_list']);
        }

        Log::info('Cache warm-up completed', ['school_id' => $schoolId]);
    }
}
