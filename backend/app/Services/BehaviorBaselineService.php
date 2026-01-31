<?php

namespace App\Services;

use App\Models\BehaviorBaseline;
use App\Models\BehaviorMetricDaily;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Behavior Baseline Service
 * 
 * Calculates and maintains rolling 14-day behavioral baselines for teachers.
 * These baselines are used as reference points for anomaly detection.
 */
class BehaviorBaselineService
{
    /**
     * Number of days to include in baseline calculation
     */
    private const BASELINE_DAYS = 14;

    /**
     * Minimum days required for reliable baseline
     */
    private const MINIMUM_DAYS = 7;

    /**
     * Cache TTL for baselines (in seconds)
     */
    private const CACHE_TTL = 3600; // 1 hour

    /**
     * Calculate and update baseline for a specific user
     */
    public function calculateBaseline(int $userId): ?BehaviorBaseline
    {
        $user = User::find($userId);
        if (!$user || $user->role_type !== 'teacher') {
            return null;
        }

        // Get metrics from last 14 days (excluding today)
        $metrics = BehaviorMetricDaily::forUser($userId)
            ->lastNDays(self::BASELINE_DAYS)
            ->orderBy('date')
            ->get();

        if ($metrics->isEmpty()) {
            Log::debug("No metrics found for user {$userId} in baseline period");
            return BehaviorBaseline::getOrCreateForUser($userId, $user->school_id);
        }

        // Calculate averages and standard deviations
        $calculations = $this->calculateStatistics($metrics);

        // Update or create baseline
        $baseline = BehaviorBaseline::updateOrCreate(
            ['user_id' => $userId],
            array_merge($calculations, [
                'school_id' => $user->school_id,
                'days_in_baseline' => $metrics->count(),
                'baseline_start_date' => $metrics->first()->date,
                'baseline_end_date' => $metrics->last()->date,
            ])
        );

        // Clear cache
        $this->clearBaselineCache($userId);

        Log::info("Baseline updated for user {$userId}", [
            'days' => $metrics->count(),
            'avg_scans' => $calculations['avg_scans_per_day'],
        ]);

        return $baseline;
    }

    /**
     * Calculate statistics from metrics collection
     */
    private function calculateStatistics(Collection $metrics): array
    {
        $count = $metrics->count();
        
        // Calculate averages
        $avgScans = $metrics->avg('total_scans') ?? 0;
        $avgSuccessful = $metrics->avg('successful_scans') ?? 0;
        $avgOutsideRadius = $metrics->avg('outside_radius_attempts') ?? 0;
        $avgDeviceMismatch = $metrics->avg('device_mismatch_attempts') ?? 0;
        $avgScheduleMismatch = $metrics->avg('schedule_mismatch_attempts') ?? 0;
        $avgScanInterval = $metrics->whereNotNull('avg_scan_interval_seconds')
                                   ->avg('avg_scan_interval_seconds');

        // Calculate failed ratio per day, then average
        $failedRatios = $metrics->map(function ($m) {
            return $m->total_scans > 0 ? $m->failed_scans / $m->total_scans : 0;
        });
        $avgFailedRatio = $failedRatios->avg() ?? 0;

        // Calculate standard deviations
        $stddevScans = $this->calculateStdDev($metrics->pluck('total_scans'));
        $stddevFailedRatio = $this->calculateStdDev($failedRatios);
        $stddevScanInterval = $this->calculateStdDev(
            $metrics->whereNotNull('avg_scan_interval_seconds')
                    ->pluck('avg_scan_interval_seconds')
        );

        return [
            'avg_scans_per_day' => round($avgScans, 2),
            'avg_successful_scans' => round($avgSuccessful, 2),
            'avg_failed_ratio' => round($avgFailedRatio, 4),
            'avg_outside_radius_attempts' => round($avgOutsideRadius, 2),
            'avg_device_mismatch_attempts' => round($avgDeviceMismatch, 2),
            'avg_schedule_mismatch_attempts' => round($avgScheduleMismatch, 2),
            'avg_scan_interval_seconds' => $avgScanInterval ? round($avgScanInterval, 2) : null,
            'stddev_scans' => round($stddevScans, 2),
            'stddev_failed_ratio' => round($stddevFailedRatio, 4),
            'stddev_scan_interval' => round($stddevScanInterval, 2),
        ];
    }

    /**
     * Calculate standard deviation
     */
    private function calculateStdDev(Collection $values): float
    {
        $count = $values->count();
        if ($count < 2) {
            return 0;
        }

        $mean = $values->avg();
        $squaredDiffs = $values->map(fn($v) => pow($v - $mean, 2));
        
        return sqrt($squaredDiffs->sum() / ($count - 1));
    }

    /**
     * Get baseline for user (with caching)
     */
    public function getBaseline(int $userId): ?BehaviorBaseline
    {
        $cacheKey = "behavior_baseline:{$userId}";

        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($userId) {
            return BehaviorBaseline::forUser($userId)->first();
        });
    }

    /**
     * Clear baseline cache for user
     */
    public function clearBaselineCache(int $userId): void
    {
        Cache::forget("behavior_baseline:{$userId}");
    }

    /**
     * Recalculate baselines for all teachers in a school
     */
    public function recalculateSchoolBaselines(int $schoolId): array
    {
        $teachers = User::where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->where('is_active', true)
            ->pluck('id');

        $results = [
            'total' => $teachers->count(),
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($teachers as $teacherId) {
            try {
                $baseline = $this->calculateBaseline($teacherId);
                if ($baseline) {
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Throwable $e) {
                $results['errors']++;
                Log::error("Failed to calculate baseline for teacher {$teacherId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Recalculate all baselines across the platform
     */
    public function recalculateAllBaselines(): array
    {
        $teachers = User::where('role_type', 'teacher')
            ->where('is_active', true)
            ->pluck('id', 'school_id');

        $results = [
            'total' => $teachers->count(),
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        foreach ($teachers as $schoolId => $teacherId) {
            try {
                $baseline = $this->calculateBaseline($teacherId);
                if ($baseline) {
                    $results['updated']++;
                } else {
                    $results['skipped']++;
                }
            } catch (\Throwable $e) {
                $results['errors']++;
                Log::error("Failed to calculate baseline for teacher {$teacherId}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Get comparison between today's metrics and baseline
     */
    public function compareToBaseline(int $userId, ?BehaviorMetricDaily $todayMetrics = null): array
    {
        $baseline = $this->getBaseline($userId);
        
        if (!$todayMetrics) {
            $todayMetrics = BehaviorMetricDaily::forUser($userId)
                ->forDate(today())
                ->first();
        }

        if (!$baseline || !$todayMetrics) {
            return [
                'has_data' => false,
                'baseline' => null,
                'today' => null,
                'deviations' => [],
            ];
        }

        $deviations = [];

        // Calculate Z-scores for key metrics
        if ($baseline->stddev_scans > 0) {
            $deviations['scans_zscore'] = ($todayMetrics->total_scans - $baseline->avg_scans_per_day) / $baseline->stddev_scans;
        }

        if ($baseline->stddev_failed_ratio > 0 && $todayMetrics->total_scans > 0) {
            $todayFailedRatio = $todayMetrics->failed_scans / $todayMetrics->total_scans;
            $deviations['failed_ratio_zscore'] = ($todayFailedRatio - $baseline->avg_failed_ratio) / $baseline->stddev_failed_ratio;
        }

        // Calculate simple ratios
        if ($baseline->avg_scans_per_day > 0) {
            $deviations['scans_ratio'] = $todayMetrics->total_scans / $baseline->avg_scans_per_day;
        }

        if ($baseline->avg_outside_radius_attempts > 0) {
            $deviations['outside_radius_ratio'] = $todayMetrics->outside_radius_attempts / $baseline->avg_outside_radius_attempts;
        }

        return [
            'has_data' => true,
            'baseline' => $baseline,
            'today' => $todayMetrics,
            'deviations' => $deviations,
        ];
    }

    /**
     * Check if a metric significantly deviates from baseline
     * Returns true if value is more than N standard deviations from mean
     */
    public function isSignificantDeviation(float $value, float $mean, float $stddev, float $threshold = 2.0): bool
    {
        if ($stddev <= 0) {
            // No standard deviation data, use simple ratio
            return $mean > 0 && ($value / $mean) > $threshold;
        }

        $zScore = abs($value - $mean) / $stddev;
        return $zScore > $threshold;
    }
}
