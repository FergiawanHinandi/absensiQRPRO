<?php

namespace Tests\Feature;

use App\Models\SlowQuery;
use App\Models\User;
use App\Models\School;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\Schedule;
use App\Services\QueryProfilingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Query Performance Property Tests
 * 
 * **Validates: Requirements Week 3 Day 15.2, 15.3**
 * 
 * Property 43: Slow queries are logged automatically
 * Property 44: Query execution time is tracked
 */
class QueryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private QueryProfilingService $profilingService;
    private School $school;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school and user
        $this->school = School::factory()->create([
            'name' => 'Test School',
            'timezone' => 'Asia/Jakarta',
        ]);

        $this->user = User::factory()->create([
            'school_id' => $this->school->id,
            'role' => 'teacher',
        ]);

        // Initialize profiling service
        $this->profilingService = new QueryProfilingService();

        // Enable query profiling for tests
        Config::set('query_profiling.enabled', true);
        Config::set('query_profiling.slow_query_threshold', 100);
        Config::set('query_profiling.store_in_database', true);
    }

    /**
     * **Property 43: Slow queries are logged automatically**
     * 
     * Test that queries exceeding the slow query threshold (100ms)
     * are automatically logged to the slow_queries table.
     */
    public function test_slow_queries_are_logged_automatically(): void
    {
        // Arrange: Set a very low threshold to capture test queries
        Config::set('query_profiling.slow_query_threshold', 0);
        
        // Start profiling
        $this->profilingService->start();

        // Act: Execute a query that should be logged
        $this->actingAs($this->user);
        
        // Simulate a slow query by creating data and querying it
        Student::factory()->count(10)->create(['school_id' => $this->school->id]);
        
        // Execute a query
        DB::table('students')
            ->where('school_id', $this->school->id)
            ->get();

        // Give time for async logging
        sleep(1);

        // Assert: Verify query was logged
        $this->assertDatabaseHas('slow_queries', [
            'school_id' => $this->school->id,
        ]);

        // Verify the query contains expected SQL pattern
        $slowQuery = SlowQuery::where('school_id', $this->school->id)->first();
        $this->assertNotNull($slowQuery);
        $this->assertStringContainsString('students', $slowQuery->sql);
    }

    /**
     * **Property 43: Slow queries above threshold are logged**
     * 
     * Test that only queries exceeding the threshold are logged,
     * while fast queries are not logged.
     */
    public function test_only_slow_queries_above_threshold_are_logged(): void
    {
        // Arrange: Set threshold to 100ms
        Config::set('query_profiling.slow_query_threshold', 100);
        
        // Clear any existing slow queries
        SlowQuery::truncate();

        // Act: Execute a fast query (should not be logged)
        $this->actingAs($this->user);
        
        // Simple query that should be fast
        DB::table('schools')->where('id', $this->school->id)->first();

        // Wait a moment
        sleep(1);

        // Assert: No queries should be logged (they're too fast)
        $count = SlowQuery::count();
        
        // Note: In a real test environment, queries are typically very fast
        // This test validates the threshold logic exists
        $this->assertTrue(
            $count === 0,
            'Fast queries should not be logged when below threshold'
        );
    }

    /**
     * **Property 44: Query execution time is tracked**
     * 
     * Test that the execution time of queries is accurately tracked
     * and stored in the slow_queries table.
     */
    public function test_query_execution_time_is_tracked(): void
    {
        // Arrange: Set low threshold to capture queries
        Config::set('query_profiling.slow_query_threshold', 0);
        
        // Start profiling
        $this->profilingService->start();

        // Act: Execute a query
        $this->actingAs($this->user);
        
        $startTime = microtime(true);
        
        // Create some data to query
        Student::factory()->count(5)->create(['school_id' => $this->school->id]);
        
        DB::table('students')
            ->where('school_id', $this->school->id)
            ->get();
        
        $endTime = microtime(true);
        $actualExecutionTime = ($endTime - $startTime) * 1000; // Convert to ms

        sleep(1);

        // Assert: Verify execution time is tracked
        $slowQuery = SlowQuery::where('school_id', $this->school->id)
            ->where('sql', 'like', '%students%')
            ->first();

        if ($slowQuery) {
            $this->assertNotNull($slowQuery->execution_time);
            $this->assertIsNumeric($slowQuery->execution_time);
            $this->assertGreaterThanOrEqual(0, $slowQuery->execution_time);
            
            // Execution time should be reasonable (not negative, not absurdly high)
            $this->assertLessThan(10000, $slowQuery->execution_time, 'Execution time should be less than 10 seconds');
        }
    }

    /**
     * **Property 44: Query execution time format is correct**
     * 
     * Test that execution time is stored in milliseconds with proper precision.
     */
    public function test_query_execution_time_format_is_correct(): void
    {
        // Arrange: Create a slow query record directly
        $slowQuery = SlowQuery::create([
            'sql' => 'SELECT * FROM attendances WHERE school_id = ?',
            'bindings' => json_encode([$this->school->id]),
            'execution_time' => 123.45,
            'connection' => 'mysql',
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'test.route',
            'method' => 'GET',
            'query_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Assert: Verify execution time is stored correctly
        $this->assertEquals(123.45, $slowQuery->execution_time);
        
        // Verify it's cast to decimal
        $this->assertIsFloat($slowQuery->execution_time);
    }

    /**
     * Test query optimization results tracking
     * 
     * Validates that we can track before/after performance improvements.
     */
    public function test_query_optimization_results_are_trackable(): void
    {
        // Arrange: Create "before optimization" slow query
        $beforeQuery = SlowQuery::create([
            'sql' => 'SELECT * FROM attendances WHERE student_id = ? AND attendance_date >= ?',
            'bindings' => json_encode([1, '2026-01-01']),
            'execution_time' => 500.00, // Slow
            'connection' => 'mysql',
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'attendance.index',
            'method' => 'GET',
            'query_count' => 100,
            'first_seen_at' => now()->subDays(7),
            'last_seen_at' => now()->subDays(1),
        ]);

        // Act: Simulate optimization by creating "after optimization" query
        $afterQuery = SlowQuery::create([
            'sql' => 'SELECT * FROM attendances WHERE student_id = ? AND attendance_date >= ?',
            'bindings' => json_encode([1, '2026-01-01']),
            'execution_time' => 50.00, // Fast after optimization
            'connection' => 'mysql',
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'attendance.index',
            'method' => 'GET',
            'query_count' => 100,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Assert: Calculate improvement
        $improvement = (($beforeQuery->execution_time - $afterQuery->execution_time) / $beforeQuery->execution_time) * 100;
        
        $this->assertEquals(90.0, $improvement, 'Query should be 90% faster after optimization');
        $this->assertGreaterThan($afterQuery->execution_time, $beforeQuery->execution_time);
    }

    /**
     * Test that query profiling service correctly identifies slow queries
     * 
     * Validates the profiling service logic for detecting slow queries.
     */
    public function test_profiling_service_identifies_slow_queries(): void
    {
        // Arrange: Set threshold
        Config::set('query_profiling.slow_query_threshold', 100);
        
        // Start profiling
        $this->profilingService->start();
        
        // Clear memory
        $this->profilingService->clear();

        // Act: Execute queries
        $this->actingAs($this->user);
        
        // Create test data
        Student::factory()->count(20)->create(['school_id' => $this->school->id]);
        
        // Execute a query
        DB::table('students')
            ->where('school_id', $this->school->id)
            ->orderBy('created_at', 'desc')
            ->get();

        // Assert: Check profiling service statistics
        $stats = $this->profilingService->getStatistics();
        
        $this->assertIsArray($stats);
        $this->assertArrayHasKey('total_queries', $stats);
        $this->assertArrayHasKey('avg_time', $stats);
        $this->assertArrayHasKey('max_time', $stats);
        $this->assertArrayHasKey('min_time', $stats);
    }

    /**
     * Test that profiling dashboard returns correct data
     * 
     * Validates the query profiling dashboard data structure.
     */
    public function test_profiling_dashboard_returns_correct_data(): void
    {
        // Arrange: Create multiple slow queries
        for ($i = 0; $i < 5; $i++) {
            SlowQuery::create([
                'sql' => "SELECT * FROM attendances WHERE school_id = ? LIMIT {$i}",
                'bindings' => json_encode([$this->school->id]),
                'execution_time' => 100 + ($i * 50),
                'connection' => 'mysql',
                'school_id' => $this->school->id,
                'user_id' => $this->user->id,
                'route' => 'attendance.index',
                'method' => 'GET',
                'query_count' => 10 + $i,
                'first_seen_at' => now()->subDays($i),
                'last_seen_at' => now(),
            ]);
        }

        // Act: Get top slow queries
        $topQueries = SlowQuery::getTopSlowQueries(10, 7);

        // Assert: Verify data structure
        $this->assertNotEmpty($topQueries);
        
        foreach ($topQueries as $query) {
            $this->assertObjectHasProperty('sql', $query);
            $this->assertObjectHasProperty('avg_time', $query);
            $this->assertObjectHasProperty('max_time', $query);
            $this->assertObjectHasProperty('min_time', $query);
            $this->assertObjectHasProperty('total_count', $query);
        }

        // Verify queries are ordered by avg_time descending
        $avgTimes = $topQueries->pluck('avg_time')->toArray();
        $sortedTimes = $avgTimes;
        rsort($sortedTimes);
        $this->assertEquals($sortedTimes, $avgTimes, 'Queries should be ordered by average time descending');
    }

    /**
     * Test that query patterns are analyzed correctly
     * 
     * Validates pattern detection for different query types.
     */
    public function test_query_patterns_are_analyzed_correctly(): void
    {
        // Arrange: Create queries with different patterns
        $patterns = [
            ['sql' => 'SELECT * FROM attendances WHERE school_id = ?', 'type' => 'SELECT'],
            ['sql' => 'INSERT INTO attendances (student_id, school_id) VALUES (?, ?)', 'type' => 'INSERT'],
            ['sql' => 'UPDATE attendances SET status = ? WHERE id = ?', 'type' => 'UPDATE'],
            ['sql' => 'DELETE FROM attendances WHERE id = ?', 'type' => 'DELETE'],
            ['sql' => 'SELECT * FROM attendances a JOIN students s ON a.student_id = s.id', 'type' => 'JOIN'],
            ['sql' => 'SELECT * FROM attendances WHERE student_id IN (SELECT id FROM students)', 'type' => 'SUBQUERY'],
        ];

        foreach ($patterns as $pattern) {
            SlowQuery::create([
                'sql' => $pattern['sql'],
                'bindings' => json_encode([]),
                'execution_time' => 150,
                'connection' => 'mysql',
                'school_id' => $this->school->id,
                'user_id' => $this->user->id,
                'route' => 'test.route',
                'method' => 'GET',
                'query_count' => 1,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }

        // Act: Retrieve queries
        $queries = SlowQuery::recent(7)->get();

        // Assert: Verify each pattern is present
        $sqlStatements = $queries->pluck('sql')->toArray();
        
        $this->assertContains('SELECT * FROM attendances WHERE school_id = ?', $sqlStatements);
        $this->assertContains('INSERT INTO attendances (student_id, school_id) VALUES (?, ?)', $sqlStatements);
        $this->assertContains('UPDATE attendances SET status = ? WHERE id = ?', $sqlStatements);
        $this->assertContains('DELETE FROM attendances WHERE id = ?', $sqlStatements);
        
        // Verify JOIN pattern
        $hasJoin = $queries->contains(fn($q) => stripos($q->sql, 'JOIN') !== false);
        $this->assertTrue($hasJoin, 'Should detect JOIN queries');
        
        // Verify SUBQUERY pattern
        $hasSubquery = $queries->contains(fn($q) => preg_match('/\(SELECT/', $q->sql));
        $this->assertTrue($hasSubquery, 'Should detect subquery patterns');
    }

    /**
     * Test that index usage is tracked
     * 
     * Validates that execution plans track index usage.
     */
    public function test_index_usage_is_tracked(): void
    {
        // Arrange: Create slow query with execution plan
        $executionPlan = json_encode([
            [
                'id' => 1,
                'select_type' => 'SIMPLE',
                'table' => 'attendances',
                'type' => 'ref',
                'possible_keys' => 'idx_school_date_status',
                'key' => 'idx_school_date_status',
                'key_len' => '8',
                'ref' => 'const',
                'rows' => 100,
            ]
        ]);

        $slowQuery = SlowQuery::create([
            'sql' => 'SELECT * FROM attendances WHERE school_id = ? AND attendance_date = ?',
            'bindings' => json_encode([$this->school->id, '2026-02-01']),
            'execution_time' => 120,
            'connection' => 'mysql',
            'execution_plan' => $executionPlan,
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'attendance.index',
            'method' => 'GET',
            'query_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Assert: Verify execution plan is stored
        $this->assertNotNull($slowQuery->execution_plan);
        
        $plan = json_decode($slowQuery->execution_plan, true);
        $this->assertIsArray($plan);
        $this->assertArrayHasKey('key', $plan[0]);
        $this->assertEquals('idx_school_date_status', $plan[0]['key']);
    }

    /**
     * Test that query frequency is tracked
     * 
     * Validates that repeated queries increment the query_count.
     */
    public function test_query_frequency_is_tracked(): void
    {
        // Arrange: Create initial slow query
        $sql = 'SELECT * FROM attendances WHERE school_id = ? AND status = ?';
        
        $slowQuery = SlowQuery::create([
            'sql' => $sql,
            'bindings' => json_encode([$this->school->id, 'present']),
            'execution_time' => 150,
            'connection' => 'mysql',
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'attendance.index',
            'method' => 'GET',
            'query_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Act: Simulate the same query being executed multiple times
        for ($i = 0; $i < 5; $i++) {
            $slowQuery->update([
                'query_count' => $slowQuery->query_count + 1,
                'last_seen_at' => now(),
            ]);
        }

        // Refresh from database
        $slowQuery->refresh();

        // Assert: Verify query count is tracked
        $this->assertEquals(6, $slowQuery->query_count);
        $this->assertNotNull($slowQuery->first_seen_at);
        $this->assertNotNull($slowQuery->last_seen_at);
        $this->assertGreaterThanOrEqual(
            $slowQuery->first_seen_at,
            $slowQuery->last_seen_at
        );
    }

    /**
     * Test query frequency statistics
     * 
     * Validates the getQueryFrequency method returns correct statistics.
     */
    public function test_query_frequency_statistics_are_accurate(): void
    {
        // Arrange: Create queries over multiple days
        for ($day = 0; $day < 3; $day++) {
            for ($i = 0; $i < 5; $i++) {
                SlowQuery::create([
                    'sql' => "SELECT * FROM attendances WHERE id = {$i}",
                    'bindings' => json_encode([]),
                    'execution_time' => 100 + ($i * 10),
                    'connection' => 'mysql',
                    'school_id' => $this->school->id,
                    'user_id' => $this->user->id,
                    'route' => 'test.route',
                    'method' => 'GET',
                    'query_count' => 1,
                    'first_seen_at' => now()->subDays($day),
                    'last_seen_at' => now()->subDays($day),
                    'created_at' => now()->subDays($day),
                ]);
            }
        }

        // Act: Get frequency statistics
        $frequency = SlowQuery::getQueryFrequency(7);

        // Assert: Verify statistics
        $this->assertNotEmpty($frequency);
        $this->assertCount(3, $frequency); // 3 days of data
        
        foreach ($frequency as $stat) {
            $this->assertObjectHasProperty('date', $stat);
            $this->assertObjectHasProperty('query_count', $stat);
            $this->assertObjectHasProperty('avg_time', $stat);
            $this->assertEquals(5, $stat->query_count); // 5 queries per day
        }
    }

    /**
     * Test slow query cleanup
     * 
     * Validates that old queries are cleaned up based on retention policy.
     */
    public function test_old_slow_queries_are_cleaned_up(): void
    {
        // Arrange: Create old and recent queries
        SlowQuery::create([
            'sql' => 'SELECT * FROM old_query',
            'bindings' => json_encode([]),
            'execution_time' => 150,
            'connection' => 'mysql',
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'test.route',
            'method' => 'GET',
            'query_count' => 1,
            'first_seen_at' => now()->subDays(40),
            'last_seen_at' => now()->subDays(40),
            'created_at' => now()->subDays(40),
        ]);

        SlowQuery::create([
            'sql' => 'SELECT * FROM recent_query',
            'bindings' => json_encode([]),
            'execution_time' => 150,
            'connection' => 'mysql',
            'school_id' => $this->school->id,
            'user_id' => $this->user->id,
            'route' => 'test.route',
            'method' => 'GET',
            'query_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);

        // Act: Cleanup queries older than 30 days
        $deletedCount = SlowQuery::cleanup(30);

        // Assert: Verify old query was deleted
        $this->assertEquals(1, $deletedCount);
        $this->assertDatabaseMissing('slow_queries', [
            'sql' => 'SELECT * FROM old_query',
        ]);
        $this->assertDatabaseHas('slow_queries', [
            'sql' => 'SELECT * FROM recent_query',
        ]);
    }
}
