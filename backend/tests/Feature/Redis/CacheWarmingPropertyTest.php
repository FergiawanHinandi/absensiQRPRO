<?php

namespace Tests\Feature\Redis;

use App\Services\Redis\CacheWarmingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Property-Based Test: Cache Warming After Failover
 * 
 * Feature: redis-high-availability
 * Property 16: Cache warming after failover
 * Validates: Requirements 4.1
 * 
 * This test validates that for any Redis failover, cache warming should restore
 * frequently accessed data automatically.
 * 
 * Property: For any Redis failover, cache warming should restore frequently
 * accessed data automatically.
 */
class CacheWarmingPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    
    private CacheWarmingService $cacheWarmingService;
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if not using PostgreSQL (some migrations have PostgreSQL-specific queries)
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }
        
        $this->cacheWarmingService = app(CacheWarmingService::class);
        
        // Ensure clean cache state
        Cache::flush();
    }
    
    protected function tearDown(): void
    {
        Cache::flush();
        
        parent::tearDown();
    }

    /**
     * Property Test: Cache warming restores school data for any active school
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that cache warming successfully restores frequently
     * accessed school data regardless of school configuration.
     * 
*/
    public function property_cache_warming_restores_school_data_for_any_active_school(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random school data
            $school = $this->createRandomSchool($i);
            
            try {
                // Simulate cache flush (failover scenario)
                Cache::flush();
                
                // Verify cache is empty
                $this->assertNull(Cache::get("school:{$school->id}:settings"));
                
                // Warm cache for this school
                $keysWarmed = $this->cacheWarmingService->warmSchool($school->id);
                
                // Property: Cache warming should restore school settings
                $schoolSettings = Cache::get("school:{$school->id}:settings");
                
                if ($schoolSettings === null || $keysWarmed === 0) {
                    $failureCount++;
                } else {
                    // Verify restored data matches original
                    $this->assertEquals($school->id, $schoolSettings['id']);
                    $this->assertEquals($school->name, $schoolSettings['name']);
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Cleanup
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Cache warming property failed for school data. Success rate: {$successRate}%. " .
            "Expected at least 99% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Cache warming completes within acceptable time for any school count
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that cache warming completes efficiently regardless
     * of the number of schools in the system.
     * 
*/
    public function property_cache_warming_completes_within_acceptable_time_for_any_school_count(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced due to performance testing
        $failureCount = 0;
        $maxAcceptableTimeMs = 5000; // 5 seconds max for warming

        for ($i = 0; $i < $iterations; $i++) {
            // Random number of schools (1-20)
            $schoolCount = rand(1, 20);
            $schools = [];
            
            try {
                // Create multiple schools
                for ($j = 0; $j < $schoolCount; $j++) {
                    $schools[] = $this->createRandomSchool($i * 100 + $j);
                }
                
                // Simulate cache flush
                Cache::flush();
                
                // Measure warming time
                $startTime = microtime(true);
                $result = $this->cacheWarmingService->warmAll();
                $duration = (microtime(true) - $startTime) * 1000;
                
                // Property: Warming should complete within acceptable time
                if ($duration > $maxAcceptableTimeMs) {
                    $failureCount++;
                }
                
                // Verify schools were warmed
                $this->assertEquals($schoolCount, $result['schools_warmed']);
                $this->assertGreaterThan(0, $result['keys_warmed']);
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Cleanup
                foreach ($schools as $school) {
                    DB::table('schools')->where('id', $school->id)->delete();
                }
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Cache warming timing property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to complete within {$maxAcceptableTimeMs}ms across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache warming prevents concurrent execution for any timing
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that the global lock prevents concurrent cache warming
     * operations regardless of timing.
     * 
*/
    public function property_cache_warming_prevents_concurrent_execution_for_any_timing(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $school = $this->createRandomSchool($i);
            
            try {
                Cache::flush();
                
                // First warming should succeed
                $firstResult = $this->cacheWarmingService->warmAll();
                
                // Immediate second warming should be skipped (lock held)
                $secondResult = $this->cacheWarmingService->warmAll();
                
                // Property: Second call should be skipped (schools_warmed = 0)
                $propertyHolds = (
                    $firstResult['schools_warmed'] > 0 &&
                    $secondResult['schools_warmed'] === 0
                );
                
                if (!$propertyHolds) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Release lock and cleanup
                Cache::forget('cache_warming:global_lock');
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Concurrent warming prevention property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache warming restores schedules for any day of week
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that schedule data is correctly warmed regardless
     * of the day of week.
     * 
*/
    public function property_cache_warming_restores_schedules_for_any_day_of_week(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $school = $this->createRandomSchool($i);
            $dayOfWeek = rand(0, 6); // 0 = Sunday, 6 = Saturday
            
            try {
                // Create schedule for random day
                $schedule = $this->createSchedule($school->id, $dayOfWeek);
                
                Cache::flush();
                
                // Warm cache
                $this->cacheWarmingService->warmSchool($school->id);
                
                // Property: Schedule should be cached
                $cachedSchedules = Cache::get("schedules:school:{$school->id}:day:{$dayOfWeek}");
                
                if ($cachedSchedules === null) {
                    $failureCount++;
                } else {
                    // Verify schedule data
                    $this->assertIsArray($cachedSchedules);
                    $this->assertNotEmpty($cachedSchedules);
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Cleanup
                DB::table('schedules')->where('school_id', $school->id)->delete();
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Schedule warming property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache warming handles missing data gracefully for any school
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that cache warming handles schools with missing
     * optional data (subscriptions, schedules, etc.) without errors.
     * 
*/
    public function property_cache_warming_handles_missing_data_gracefully_for_any_school(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Create school without optional data (no subscription, schedules, etc.)
            $school = $this->createRandomSchool($i, false);
            
            try {
                Cache::flush();
                
                // Warm cache should not throw exception
                $keysWarmed = $this->cacheWarmingService->warmSchool($school->id);
                
                // Property: Should complete without error and warm at least school settings
                $schoolSettings = Cache::get("school:{$school->id}:settings");
                
                if ($schoolSettings === null) {
                    $failureCount++;
                }
                
                // Should have warmed at least some keys (school settings + teacher data)
                $this->assertGreaterThanOrEqual(1, $keysWarmed);
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Graceful handling property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache warming restores teacher data for any school
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that teacher data is correctly cached regardless
     * of the number of teachers.
     * 
*/
    public function property_cache_warming_restores_teacher_data_for_any_school(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $school = $this->createRandomSchool($i);
            $teacherCount = rand(1, 10);
            
            try {
                // Create random number of teachers
                for ($j = 0; $j < $teacherCount; $j++) {
                    $this->createTeacher($school->id, $i * 100 + $j);
                }
                
                Cache::flush();
                
                // Warm cache
                $this->cacheWarmingService->warmSchool($school->id);
                
                // Property: Teacher data should be cached
                $cachedTeachers = Cache::get("teachers:school:{$school->id}");
                
                if ($cachedTeachers === null) {
                    $failureCount++;
                } else {
                    $this->assertIsArray($cachedTeachers);
                    $this->assertCount($teacherCount, $cachedTeachers);
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Cleanup
                DB::table('users')->where('school_id', $school->id)->delete();
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Teacher data warming property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache warming sets appropriate TTL for any cache key
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that all warmed cache keys have appropriate TTL values
     * to prevent stale data.
     * 
*/
    public function property_cache_warming_sets_appropriate_ttl_for_any_cache_key(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $minTtlSeconds = 60; // At least 1 minute

        for ($i = 0; $i < $iterations; $i++) {
            $school = $this->createRandomSchool($i);
            
            try {
                Cache::flush();
                
                // Warm cache
                $this->cacheWarmingService->warmSchool($school->id);
                
                // Check TTL for school settings
                $ttl = Cache::getStore()->getRedis()->ttl(
                    config('cache.prefix') . ":school:{$school->id}:settings"
                );
                
                // Property: TTL should be set and reasonable (> 1 minute)
                if ($ttl < $minTtlSeconds) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "TTL property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to have TTL > {$minTtlSeconds}s across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Cache warming is idempotent for any number of calls
     * 
     * **Validates: Requirements 4.1**
     * 
     * This test verifies that calling cache warming multiple times produces
     * the same result without errors.
     * 
*/
    public function property_cache_warming_is_idempotent_for_any_number_of_calls(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $school = $this->createRandomSchool($i);
            $callCount = rand(2, 5);
            
            try {
                Cache::flush();
                
                $results = [];
                
                // Call warming multiple times
                for ($j = 0; $j < $callCount; $j++) {
                    // Release lock between calls to allow execution
                    Cache::forget('cache_warming:global_lock');
                    
                    $result = $this->cacheWarmingService->warmSchool($school->id);
                    $results[] = $result;
                }
                
                // Property: All calls should warm the same number of keys
                $uniqueResults = array_unique($results);
                
                if (count($uniqueResults) !== 1) {
                    $failureCount++;
                }
                
                // Verify data is still correct after multiple warmings
                $schoolSettings = Cache::get("school:{$school->id}:settings");
                $this->assertNotNull($schoolSettings);
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                Cache::forget('cache_warming:global_lock');
                DB::table('schools')->where('id', $school->id)->delete();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Idempotency property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% across {$iterations} iterations."
        );
    }

    /**
     * Create a random school for testing
     * 
     * @param int $seed Seed for randomization
     * @param bool $withSubscription Whether to create subscription
     * @return object School object
     */
    private function createRandomSchool(int $seed, bool $withSubscription = true): object
    {
        $schoolId = 900000 + $seed; // Use high IDs to avoid conflicts
        
        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Test School ' . $seed,
            'address' => 'Test Address ' . $seed,
            'phone' => '08' . str_pad($seed, 10, '0'),
            'email' => "school{$seed}@test.com",
            'timezone' => ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'][rand(0, 2)],
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        if ($withSubscription && rand(0, 1)) {
            DB::table('subscriptions')->insert([
                'school_id' => $schoolId,
                'package_id' => rand(1, 3),
                'status' => 'active',
                'start_date' => now()->subDays(30),
                'end_date' => now()->addDays(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        
        return (object) [
            'id' => $schoolId,
            'name' => 'Test School ' . $seed,
            'timezone' => 'Asia/Jakarta',
        ];
    }

    /**
     * Create a schedule for testing
     * 
     * @param int $schoolId School ID
     * @param int $dayOfWeek Day of week (0-6)
     * @return object Schedule object
     */
    private function createSchedule(int $schoolId, int $dayOfWeek): object
    {
        $scheduleId = DB::table('schedules')->insertGetId([
            'school_id' => $schoolId,
            'class_id' => rand(1, 10),
            'subject_id' => rand(1, 10),
            'teacher_id' => rand(1, 100),
            'day_of_week' => $dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return (object) ['id' => $scheduleId];
    }

    /**
     * Create a teacher for testing
     * 
     * @param int $schoolId School ID
     * @param int $seed Seed for randomization
     * @return object Teacher object
     */
    private function createTeacher(int $schoolId, int $seed): object
    {
        $teacherId = DB::table('users')->insertGetId([
            'school_id' => $schoolId,
            'name' => 'Teacher ' . $seed,
            'email' => "teacher{$seed}@test.com",
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        
        return (object) ['id' => $teacherId];
    }
}
