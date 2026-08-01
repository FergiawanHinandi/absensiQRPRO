<?php

namespace App\Services\Redis;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Cache Warming Service for Redis High Availability
 *
 * Pre-warms frequently accessed data after Redis failover/restart.
 * Implements school-specific warming strategies.
 *
 * Spec: redis-high-availability / tasks.md Task 6.1
 */
class CacheWarmingService
{
    private const WARMING_KEY_PREFIX = 'cache_warming:';
    private const WARMING_LOCK_TTL   = 60; // 1 minute lock to prevent concurrent warming

    /**
     * Warm all critical caches for all active schools.
     */
    public function warmAll(): array
    {
        $results = [
            'schools_warmed' => 0,
            'keys_warmed'    => 0,
            'errors'         => [],
            'duration_ms'    => 0,
        ];

        $startTime = microtime(true);

        // Prevent concurrent warming runs
        $lock = cache()->lock(self::WARMING_KEY_PREFIX . 'global_lock', self::WARMING_LOCK_TTL);
        if (!$lock->get()) {
            Log::info('CacheWarmingService: Another warming job is running, skipping');
            return $results;
        }

        try {
            $schools = DB::table('schools')
                ->where('is_active', true)
                ->select('id', 'name', 'timezone')
                ->get();

            foreach ($schools as $school) {
                try {
                    $warmed             = $this->warmSchool($school->id);
                    $results['keys_warmed'] += $warmed;
                    $results['schools_warmed']++;
                } catch (\Exception $e) {
                    $results['errors'][] = "School {$school->id}: {$e->getMessage()}";
                    Log::warning('CacheWarmingService: Error warming school', [
                        'school_id' => $school->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }

            // Warm global configs
            $results['keys_warmed'] += $this->warmGlobalConfigs();

        } finally {
            $lock->release();
        }

        $results['duration_ms'] = round((microtime(true) - $startTime) * 1000);

        Log::info('CacheWarmingService: Warming completed', $results);
        return $results;
    }

    /**
     * Warm cache for a specific school.
     * Returns number of keys warmed.
     */
    public function warmSchool(int $schoolId): int
    {
        $warmed = 0;

        // 1. School settings/subscription
        $warmed += $this->warmSchoolSettings($schoolId);

        // 2. Active schedules for today
        $warmed += $this->warmSchedules($schoolId);

        // 3. Today's attendance summary
        $warmed += $this->warmAttendanceSummary($schoolId);

        // 4. Active teachers and their device registrations
        $warmed += $this->warmTeacherData($schoolId);

        Log::debug("CacheWarmingService: School {$schoolId} warmed {$warmed} keys");
        return $warmed;
    }

    /**
     * Warm school settings and subscription status.
     */
    private function warmSchoolSettings(int $schoolId): int
    {
        $school = DB::table('schools')
            ->where('id', $schoolId)
            ->first();

        if (!$school) {
            return 0;
        }

        // Cache school settings
        Cache::put(
            "school:{$schoolId}:settings",
            (array) $school,
            now()->addHours(1)
        );

        // Cache active subscription
        $subscription = DB::table('subscriptions')
            ->where('school_id', $schoolId)
            ->where('status', 'active')
            ->where('end_date', '>', now())
            ->first();

        if ($subscription) {
            Cache::put(
                "subscription:school:{$schoolId}",
                (array) $subscription,
                now()->addMinutes(60)
            );
        }

        return 2;
    }

    /**
     * Warm today's schedules.
     */
    private function warmSchedules(int $schoolId): int
    {
        $dayOfWeek = now()->dayOfWeek;

        $schedules = DB::table('schedules')
            ->where('school_id', $schoolId)
            ->where('day_of_week', $dayOfWeek)
            ->where('is_active', true)
            ->get()
            ->toArray();

        Cache::put(
            "schedules:school:{$schoolId}:day:{$dayOfWeek}",
            $schedules,
            now()->addHours(4)
        );

        return 1;
    }

    /**
     * Warm today's attendance summary.
     */
    private function warmAttendanceSummary(int $schoolId): int
    {
        $today = today()->toDateString();

        $summary = DB::table('attendance_daily_summaries')
            ->where('school_id', $schoolId)
            ->where('summary_date', $today)
            ->first();

        if ($summary) {
            Cache::put(
                "attendance:summary:school:{$schoolId}:date:{$today}",
                (array) $summary,
                now()->addMinutes(15)
            );
            return 1;
        }

        return 0;
    }

    /**
     * Warm teacher device data.
     */
    private function warmTeacherData(int $schoolId): int
    {
        $teachers = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->where('is_active', true)
            ->select('id', 'name', 'email')
            ->get()
            ->toArray();

        Cache::put(
            "teachers:school:{$schoolId}",
            $teachers,
            now()->addHours(2)
        );

        return 1;
    }

    /**
     * Warm global (non-school-specific) configs.
     */
    private function warmGlobalConfigs(): int
    {
        $warmed = 0;

        // Cache application settings
        Cache::put('app:settings:global', config('app'), now()->addHours(6));
        $warmed++;

        // Cache rate limiting configs if table exists
        if (DB::getSchemaBuilder()->hasTable('rate_limit_configs')) {
            $rlConfigs = DB::table('rate_limit_configs')
                ->where('school_id', null)
                ->where('is_active', true)
                ->get()
                ->toArray();

            Cache::put('rate_limit:global_configs', $rlConfigs, now()->addHours(1));
            $warmed++;
        }

        return $warmed;
    }

    /**
     * Get cache hit ratio statistics for monitoring.
     */
    public function getHitRatioStats(): array
    {
        // Track hit ratio via Redis INFO command
        try {
            $redis = cache()->getStore()->getRedis()->connection();
            $info  = $redis->info('stats');

            $hits   = $info['keyspace_hits'] ?? 0;
            $misses = $info['keyspace_misses'] ?? 0;
            $total  = $hits + $misses;

            return [
                'hits'      => $hits,
                'misses'    => $misses,
                'total'     => $total,
                'hit_ratio' => $total > 0 ? round($hits / $total * 100, 2) : 0,
            ];
        } catch (\Exception $e) {
            return ['error' => $e->getMessage()];
        }
    }
}
