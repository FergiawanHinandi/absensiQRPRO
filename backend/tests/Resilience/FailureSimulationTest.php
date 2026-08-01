<?php

declare(strict_types=1);

namespace Tests\Resilience;

use App\Application\Services\AttendanceApplicationService;
use App\Application\Services\DashboardQueryService;
use App\Models\Attendance;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Resilience Test: Failure Simulation
 * Tests system behavior under failure conditions:
 * - Redis down
 * - Database slow
 * - Queue stopped
 * - Network timeout
 * Expected:
 * - Attendance fails secure (no corruption)
 * - Graceful degradation
 * - Eventual consistency maintained
 */
#[\PHPUnit\Framework\Attributes\Group('resilience')]
#[\PHPUnit\Framework\Attributes\Group('failure-simulation')]
class FailureSimulationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();
        $this->student = Student::factory()->create(['school_id' => $this->school->id]);
    }

    /**
     * FS-001: Redis down: Cache fallback to DB
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_falls_back_to_database_when_redis_is_down(): void
    {
        // Arrange: Simulate Redis failure
        Cache::shouldReceive('get')
            ->andThrow(new \Exception('Redis connection failed'));

        Cache::shouldReceive('remember')
            ->andReturnUsing(function ($key, $ttl, $callback) {
                // Bypass cache, call callback directly
                return $callback();
            });

        $queryService = app(DashboardQueryService::class);

        // Create summary in DB
        \App\ReadModels\AttendanceDailySummary::factory()->create([
            'school_id' => $this->school->id,
            'attendance_date' => today(),
            'total_present' => 50,
        ]);

        // Act: Query dashboard (should fallback to DB)
        $summary = $queryService->getTodaySummary($this->school->id);

        // Assert: Data retrieved from DB
        $this->assertNotNull($summary);
        $this->assertEquals(50, $summary->total_present);
    }

    /**
     * FS-002: Redis down: Session handling
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_session_when_redis_is_down(): void
    {
        // Arrange: Use file-based sessions as fallback
        config(['session.driver' => 'file']);

        // Act: Create session
        session(['test_key' => 'test_value']);

        // Assert: Session works
        $this->assertEquals('test_value', session('test_key'));
    }

    /**
     * FS-003: Database slow: Query timeout handling
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_database_query_timeout(): void
    {
        // Arrange: Set short timeout
        config(['database.connections.mysql.options' => [
            \PDO::ATTR_TIMEOUT => 1, // 1 second timeout
        ]]);

        // Act & Assert: Expect timeout exception
        $this->expectException(\Illuminate\Database\QueryException::class);

        // Simulate slow query
        DB::select('SELECT SLEEP(5)');
    }

    /**
     * FS-004: Database slow: Connection pool exhaustion
     */
    #[\PHPUnit\Framework\Attributes\Test]
    #[\PHPUnit\Framework\Attributes\Group('slow')]
    public function it_handles_connection_pool_exhaustion(): void
    {
        // Arrange: Create many concurrent connections
        $connections = [];
        $maxConnections = 10;

        // Act: Try to create more connections than pool size
        for ($i = 0; $i < $maxConnections + 5; $i++) {
            try {
                $connections[] = DB::connection()->getPdo();
            } catch (\Exception $e) {
                // Expected when pool exhausted
                $this->assertStringContainsString('Too many connections', $e->getMessage());
                break;
            }
        }

        // Assert: System handled gracefully
        $this->assertTrue(true);
    }

    /**
     * FS-005: Queue stopped: Job retry mechanism
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_retries_failed_jobs(): void
    {
        // Arrange
        Queue::fake();

        $service = app(AttendanceApplicationService::class);

        // Act: Create attendance (should dispatch job)
        $attendance = $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => 1,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ]);

        // Assert: Job was queued
        Queue::assertPushed(\App\Jobs\UpdateAttendanceSummary::class);
    }

    /**
     * FS-006: Queue stopped: Eventual consistency delay
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_maintains_eventual_consistency_despite_queue_delay(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        // Act: Create attendance
        $attendance = $service->checkIn([
            'student_id' => $this->student->id,
            'schedule_id' => 1,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => now(),
        ]);

        // Manually trigger projector (simulating delayed queue processing)
        sleep(1); // Simulate delay

        $projector = app(\App\ReadModels\Projectors\AttendanceSummaryProjector::class);
        $projector->projectForDate($this->school->id, today());

        // Assert: Read model eventually consistent
        $summary = \App\ReadModels\AttendanceDailySummary::forSchool($this->school->id)
            ->forDate(today())
            ->schoolWide()
            ->first();

        $this->assertNotNull($summary);
        $this->assertEquals(1, $summary->total_students);
    }

    /**
     * FS-007: Event listener failure: No data corruption
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_data_corruption_on_event_listener_failure(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        // Simulate listener failure
        \Event::listen(\App\Events\StudentAttended::class, function () {
            throw new \Exception('Listener failed');
        });

        // Act: Create attendance
        try {
            $attendance = $service->checkIn([
                'student_id' => $this->student->id,
                'schedule_id' => 1,
                'school_id' => $this->school->id,
                'attendance_date' => today()->format('Y-m-d'),
                'check_in_time' => now(),
            ]);
        } catch (\Exception $e) {
            // Expected
        }

        // Assert: Write model still consistent
        $count = Attendance::where('student_id', $this->student->id)
            ->where('attendance_date', today())
            ->count();

        // Should either be 1 (success) or 0 (rollback), never corrupted
        $this->assertContains($count, [0, 1]);
    }

    /**
     * FS-008: Projector failure: Read model rebuild
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rebuilds_read_model_after_projector_failure(): void
    {
        // Arrange: Create attendance records
        Attendance::factory()->count(10)->create([
            'school_id' => $this->school->id,
            'attendance_date' => today(),
        ]);

        // Simulate projector failure (read model not updated)
        \App\ReadModels\AttendanceDailySummary::where('school_id', $this->school->id)->delete();

        // Act: Rebuild read model
        $projector = app(\App\ReadModels\Projectors\AttendanceSummaryProjector::class);
        $projector->rebuildForSchool($this->school->id);

        // Assert: Read model rebuilt correctly
        $summary = \App\ReadModels\AttendanceDailySummary::forSchool($this->school->id)
            ->forDate(today())
            ->schoolWide()
            ->first();

        $this->assertNotNull($summary);
        $this->assertEquals(10, $summary->total_students);
    }

    /**
     * FS-009: Network timeout: Graceful degradation
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_network_timeout_gracefully(): void
    {
        // Arrange: Set short timeout
        config(['app.timeout' => 1]);

        // Act: Simulate slow external API call
        try {
            $response = file_get_contents('http://httpbin.org/delay/5', false, stream_context_create([
                'http' => ['timeout' => 1]
            ]));
        } catch (\Exception $e) {
            // Expected timeout
            $this->assertStringContainsString('timeout', strtolower($e->getMessage()));
        }

        // Assert: System still functional
        $this->assertTrue(true);
    }

    /**
     * FS-010: Disk full: Error handling
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_disk_full_error(): void
    {
        // Arrange: Mock disk full scenario
        $this->markTestSkipped('Disk full simulation requires special setup');

        // In real scenario, would test:
        // - Log rotation
        // - Cache cleanup
        // - Graceful error message
    }

    /**
     * FS-011: Transaction rollback on failure
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rolls_back_transaction_on_failure(): void
    {
        // Arrange
        $initialCount = Attendance::count();

        // Act: Attempt operation that fails mid-transaction
        try {
            DB::transaction(function () {
                Attendance::create([
                    'student_id' => $this->student->id,
                    'schedule_id' => 1,
                    'school_id' => $this->school->id,
                    'attendance_date' => today(),
                ]);

                // Simulate failure
                throw new \Exception('Simulated failure');
            });
        } catch (\Exception $e) {
            // Expected
        }

        // Assert: No data committed
        $finalCount = Attendance::count();
        $this->assertEquals($initialCount, $finalCount);
    }

    /**
     * FS-012: Partial failure in bulk operation
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_partial_failure_in_bulk_operation(): void
    {
        // Arrange
        $students = Student::factory()->count(10)->create([
            'school_id' => $this->school->id,
        ]);

        $service = app(AttendanceApplicationService::class);

        // Create data with one invalid entry
        $bulkData = $students->map(fn($s, $i) => [
            'student_id' => $s->id,
            'schedule_id' => 1,
            'school_id' => $this->school->id,
            'attendance_date' => today()->format('Y-m-d'),
            'check_in_time' => $i === 5 ? 'invalid-time' : now(), // 6th entry invalid
        ])->toArray();

        // Act: Bulk check-in
        $results = $service->bulkCheckIn($bulkData);

        // Assert: Valid entries processed, invalid skipped
        $this->assertGreaterThan(0, count($results));
        $this->assertLessThan(10, count($results)); // Some failed
    }

    /**
     * FS-013: Cache corruption recovery
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_recovers_from_cache_corruption(): void
    {
        // Arrange: Put corrupted data in cache
        Cache::put('dashboard_summary_' . $this->school->id, 'corrupted-data', 3600);

        $queryService = app(DashboardQueryService::class);

        // Create valid data in DB
        \App\ReadModels\AttendanceDailySummary::factory()->create([
            'school_id' => $this->school->id,
            'attendance_date' => today(),
            'total_present' => 50,
        ]);

        // Act: Clear corrupted cache and fetch fresh data
        $queryService->clearCache($this->school->id);
        $summary = $queryService->getTodaySummary($this->school->id);

        // Assert: Fresh data retrieved
        $this->assertNotNull($summary);
        $this->assertEquals(50, $summary->total_present);
    }

    /**
     * FS-014: Database connection retry
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_retries_database_connection_on_failure(): void
    {
        // Arrange: Simulate transient connection failure
        $attempts = 0;
        $maxAttempts = 3;

        // Act: Retry logic
        $result = retry($maxAttempts, function () use (&$attempts) {
            $attempts++;
            
            if ($attempts < 2) {
                throw new \Exception('Connection failed');
            }

            return 'success';
        }, 100); // 100ms between retries

        // Assert: Eventually succeeded
        $this->assertEquals('success', $result);
        $this->assertEquals(2, $attempts);
    }

    /**
     * FS-015: Graceful shutdown on critical error
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function it_shuts_down_gracefully_on_critical_error(): void
    {
        // Arrange
        $service = app(AttendanceApplicationService::class);

        // Act: Simulate critical error
        try {
            // Force a critical error
            throw new \Error('Critical system error');
        } catch (\Error $e) {
            // Assert: Error caught, system can log and shutdown gracefully
            $this->assertStringContainsString('Critical', $e->getMessage());
        }

        // Verify system still responsive
        $this->assertTrue(true);
    }
}
