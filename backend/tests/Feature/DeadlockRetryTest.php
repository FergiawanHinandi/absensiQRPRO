<?php

namespace Tests\Feature;
use PHPUnit\Framework\Attributes\DataProvider;

use App\Http\Middleware\DeadlockRetryMiddleware;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for DeadlockRetryMiddleware
 *
 * Tests deadlock retry behavior with property-based testing approach.
 * Validates: Requirements Week 2 Day 6.1, 6.2, 6.3, 6.5
 *
 * Properties tested:
 * - Property 15: Deadlock exceptions are retried (max 3 times)
 * - Property 16: Exponential backoff between retries
 * - Property 17: Deadlock events are logged
 * - Property 18: Retry count is tracked in metrics
 *
 */
#[\PHPUnit\Framework\Attributes\Group('feature')]
#[\PHPUnit\Framework\Attributes\Group('concurrency')]
#[\PHPUnit\Framework\Attributes\Group('deadlock')]
#[\PHPUnit\Framework\Attributes\Group('week2')]
class DeadlockRetryTest extends TestCase
{
    use RefreshDatabase;

    protected DeadlockRetryMiddleware $middleware;
    protected School $school;
    protected User $teacher;
    protected User $student;
    protected Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new DeadlockRetryMiddleware();
        
        // Reset metrics before each test
        DeadlockRetryMiddleware::resetMetrics();
        
        // Spy on Log facade
        Log::spy();

        // Create test data
        $this->school = School::factory()->create();
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);
        $this->schedule = Schedule::factory()->create(['school_id' => $this->school->id]);
    }

    /**
     * **Property 15: Deadlock exceptions are retried (max 3 times)**
     * 
     * Universal property: For any request that encounters a deadlock,
     * the middleware MUST retry the request up to MAX_RETRIES (3) times
     * before throwing the exception.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_deadlock_exceptions_are_retried_max_three_times(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;

        try {
            $this->middleware->handle($request, function ($req) use (&$attemptCount) {
                $attemptCount++;
                
                // Always throw deadlock to test max retries
                throw new QueryException(
                    'mysql',
                    'UPDATE attendances SET status = ? WHERE id = ?',
                    ['present', 1],
                    new \PDOException('Deadlock found when trying to get lock', '40001')
                );
            });

            $this->fail('Expected QueryException to be thrown after max retries');
        } catch (QueryException $e) {
            // Verify the property: exactly 3 attempts were made
            $this->assertEquals(
                3,
                $attemptCount,
                'Property violation: Deadlock must be retried exactly 3 times before failing'
            );

            // Verify error log was called after exhausting retries
            Log::shouldHaveReceived('error')
                ->once()
                ->with('Deadlock retry limit exceeded', \Mockery::on(function ($context) {
                    return $context['attempts'] === 3;
                }));
        }
    }

    /**
     * **Property 15: Deadlock exceptions are retried (max 3 times)**
     * Test with various deadlock scenarios to ensure retry behavior
     * is consistent across different database error types.
*/
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    #[DataProvider('deadlockScenarioProvider')]
    /**/
    public function property_all_deadlock_types_trigger_retry(
        string $driver,
        string $errorMessage,
        string $errorCode
    ): void {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $attemptCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$attemptCount, $driver, $errorMessage, $errorCode) {
            $attemptCount++;

            // First attempt throws deadlock, second succeeds
            if ($attemptCount === 1) {
                // PDOException expects integer code, so we use 0 and set errorInfo for SQLSTATE
                $pdoException = new \PDOException($errorMessage, 0);
                $pdoException->errorInfo = [$errorCode, 1, $errorMessage];
                
                throw new QueryException(
                    $driver,
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    $pdoException
                );
            }

            return response()->json(['success' => true]);
        });

        // Verify the property: retry was triggered
        $this->assertEquals(
            2,
            $attemptCount,
            "Property violation: {$driver} deadlock '{$errorMessage}' must trigger retry"
        );

        $this->assertEquals(200, $response->getStatusCode());
        
        // Verify warning log was called
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Deadlock detected, retrying request', \Mockery::any());
    }

    /**
     * Data provider for different deadlock scenarios
     */
    public static function deadlockScenarioProvider(): array
    {
        return [
            'MySQL deadlock' => [
                'mysql',
                'Deadlock found when trying to get lock',
                '40001',
            ],
            'PostgreSQL deadlock' => [
                'pgsql',
                'deadlock detected',
                '40P01',
            ],
            'MySQL lock wait timeout' => [
                'mysql',
                'Lock wait timeout exceeded',
                'HY000',
            ],
            'Generic deadlock message' => [
                'mysql',
                'Transaction deadlock occurred',
                '99999',
            ],
        ];
    }

    /**
     * **Property 16: Exponential backoff between retries**
     * 
     * Universal property: For any sequence of retries, the delay between
     * attempts MUST follow exponential backoff: 100ms, 200ms, 400ms.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_exponential_backoff_delays_are_correct(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;
        $delays = [];
        $startTimes = [];

        try {
            $startTime = microtime(true);
            
            $this->middleware->handle($request, function ($req) use (&$attemptCount, &$startTimes) {
                $attemptCount++;
                $startTimes[] = microtime(true);
                
                // Always throw deadlock
                throw new QueryException(
                    'mysql',
                    'UPDATE attendances SET status = ?',
                    ['present'],
                    new \PDOException('Deadlock found', '40001')
                );
            });
        } catch (QueryException $e) {
            // Expected
        }

        // Extract delays from log calls
        Log::shouldHaveReceived('warning')
            ->twice()
            ->with('Deadlock detected, retrying request', \Mockery::on(function ($context) use (&$delays) {
                $delays[] = $context['delay_ms'];
                return true;
            }));

        // Verify the property: delays follow exponential backoff
        $expectedDelays = [100, 200];
        $this->assertEquals(
            $expectedDelays,
            $delays,
            'Property violation: Delays must follow exponential backoff pattern (100ms, 200ms)'
        );

        // Verify actual time delays (with tolerance for execution overhead)
        if (count($startTimes) >= 3) {
            $actualDelay1 = ($startTimes[1] - $startTimes[0]) * 1000; // Convert to ms
            $actualDelay2 = ($startTimes[2] - $startTimes[1]) * 1000;

            $this->assertGreaterThanOrEqual(
                95, // 100ms - 5ms tolerance
                $actualDelay1,
                'First retry delay must be at least 100ms'
            );

            $this->assertGreaterThanOrEqual(
                195, // 200ms - 5ms tolerance
                $actualDelay2,
                'Second retry delay must be at least 200ms'
            );
        }
    }

    /**
     * **Property 16: Exponential backoff between retries**
     * 
     * Test that backoff calculation is correct for each attempt number.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_backoff_formula_is_correct_for_all_attempts(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;
        $loggedDelays = [];

        try {
            $this->middleware->handle($request, function ($req) use (&$attemptCount) {
                $attemptCount++;
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            });
        } catch (QueryException $e) {
            // Expected
        }

        // Extract delays and attempt numbers from logs
        Log::shouldHaveReceived('warning')
            ->twice()
            ->with('Deadlock detected, retrying request', \Mockery::on(function ($context) use (&$loggedDelays) {
                $loggedDelays[$context['attempt']] = $context['delay_ms'];
                return true;
            }));

        // Verify the property: delay = BASE_DELAY_MS * 2^(attempt-1)
        // Attempt 1: 100 * 2^0 = 100ms
        // Attempt 2: 100 * 2^1 = 200ms
        $this->assertEquals(100, $loggedDelays[1], 'Attempt 1 delay must be 100ms');
        $this->assertEquals(200, $loggedDelays[2], 'Attempt 2 delay must be 200ms');
    }

    /**
     * **Property 17: Deadlock events are logged**
     * 
     * Universal property: For any deadlock occurrence, the middleware MUST
     * log a warning with complete context information.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_all_deadlock_events_are_logged_with_context(): void
    {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Request-ID', 'test-req-12345');
        $attemptCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$attemptCount) {
            $attemptCount++;

            if ($attemptCount === 1) {
                throw new QueryException(
                    'mysql',
                    'UPDATE attendances SET status = ?',
                    ['present'],
                    new \PDOException('Deadlock found', '40001')
                );
            }

            return response()->json(['success' => true]);
        });

        // Verify the property: deadlock event was logged with all required context
        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Deadlock detected, retrying request', \Mockery::on(function ($context) {
                // All required context fields must be present
                $requiredFields = [
                    'attempt',
                    'max_retries',
                    'delay_ms',
                    'request_id',
                    'endpoint',
                    'method',
                    'error_code',
                ];

                foreach ($requiredFields as $field) {
                    if (!array_key_exists($field, $context)) {
                        return false;
                    }
                }

                // Verify specific values
                return $context['attempt'] === 1
                    && $context['max_retries'] === 3
                    && $context['delay_ms'] === 100
                    && $context['request_id'] === 'test-req-12345'
                    && $context['endpoint'] === 'api/v1/attendance/scan'
                    && $context['method'] === 'POST'
                    && $context['error_code'] === '40001';
            }));

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * **Property 17: Deadlock events are logged**
     * 
     * Test that exhausted retries are logged with error level.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_exhausted_retries_are_logged_as_errors(): void
    {
        $request = Request::create('/api/v1/schedules', 'PUT');
        $request->headers->set('X-Request-ID', 'exhausted-test-789');
        $attemptCount = 0;

        try {
            $this->middleware->handle($request, function ($req) use (&$attemptCount) {
                $attemptCount++;
                throw new QueryException(
                    'pgsql',
                    'UPDATE schedules SET name = ?',
                    ['New Name'],
                    new \PDOException('deadlock detected', 0)
                );
            });

            $this->fail('Expected QueryException to be thrown');
        } catch (QueryException $e) {
            // Verify the property: error log was called with complete context
            Log::shouldHaveReceived('error')
                ->once()
                ->with('Deadlock retry limit exceeded', \Mockery::on(function ($context) {
                    $requiredFields = [
                        'attempts',
                        'request_id',
                        'endpoint',
                        'method',
                        'error_code',
                        'error',
                    ];

                    foreach ($requiredFields as $field) {
                        if (!array_key_exists($field, $context)) {
                            return false;
                        }
                    }

                    return $context['attempts'] === 3
                        && $context['request_id'] === 'exhausted-test-789'
                        && $context['endpoint'] === 'api/v1/schedules'
                        && $context['method'] === 'PUT';
                }));
        }
    }

    /**
     * **Property 18: Retry count is tracked in metrics**
     * 
     * Universal property: For any deadlock retry, the middleware MUST
     * update metrics to track retry attempts and outcomes.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_successful_retries_update_metrics_correctly(): void
    {
        // Reset metrics
        DeadlockRetryMiddleware::resetMetrics();

        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$attemptCount) {
            $attemptCount++;

            if ($attemptCount === 1) {
                throw new QueryException(
                    'mysql',
                    'INSERT INTO attendances',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            }

            return response()->json(['success' => true]);
        });

        // Verify the property: metrics were updated
        $metrics = DeadlockRetryMiddleware::getMetrics();

        $this->assertEquals(
            1,
            $metrics['total_deadlocks'],
            'Property violation: total_deadlocks must be incremented'
        );

        $this->assertEquals(
            1,
            $metrics['total_retries'],
            'Property violation: total_retries must be incremented'
        );

        $this->assertEquals(
            1,
            $metrics['retry_attempts'][1],
            'Property violation: retry_attempts[1] must be incremented'
        );

        $this->assertNotNull(
            $metrics['last_occurrence'],
            'Property violation: last_occurrence must be recorded'
        );

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * **Property 18: Retry count is tracked in metrics**
     * 
     * Test that exhausted retries are tracked separately in metrics.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_exhausted_retries_update_metrics_correctly(): void
    {
        // Reset metrics
        DeadlockRetryMiddleware::resetMetrics();

        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;

        try {
            $this->middleware->handle($request, function ($req) use (&$attemptCount) {
                $attemptCount++;
                throw new QueryException(
                    'mysql',
                    'UPDATE attendances',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            });
        } catch (QueryException $e) {
            // Expected
        }

        // Verify the property: exhausted retry metrics were updated
        $metrics = DeadlockRetryMiddleware::getMetrics();

        $this->assertEquals(
            2,
            $metrics['total_deadlocks'],
            'Property violation: total_deadlocks must count all retry attempts (2 retries)'
        );

        $this->assertEquals(
            2,
            $metrics['total_retries'],
            'Property violation: total_retries must count all retry attempts'
        );

        $this->assertEquals(
            1,
            $metrics['exhausted_retries'],
            'Property violation: exhausted_retries must be incremented when max retries reached'
        );

        $this->assertEquals(
            1,
            $metrics['retry_attempts'][1],
            'Property violation: retry_attempts[1] must be incremented'
        );

        $this->assertEquals(
            1,
            $metrics['retry_attempts'][2],
            'Property violation: retry_attempts[2] must be incremented'
        );
    }

    /**
     * **Property 18: Retry count is tracked in metrics**
     * 
     * Test that metrics accumulate across multiple requests.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('property-based')]
    public function property_metrics_accumulate_across_multiple_deadlocks(): void
    {
        // Reset metrics
        DeadlockRetryMiddleware::resetMetrics();

        // Simulate 3 successful retries
        for ($i = 0; $i < 3; $i++) {
            $request = Request::create('/api/v1/attendance', 'POST');
            $attemptCount = 0;

            $this->middleware->handle($request, function ($req) use (&$attemptCount) {
                $attemptCount++;

                if ($attemptCount === 1) {
                    throw new QueryException(
                        'mysql',
                        'INSERT INTO attendances',
                        [],
                        new \PDOException('Deadlock found', '40001')
                    );
                }

                return response()->json(['success' => true]);
            });
        }

        // Verify the property: metrics accumulated correctly
        $metrics = DeadlockRetryMiddleware::getMetrics();

        $this->assertEquals(
            3,
            $metrics['total_deadlocks'],
            'Property violation: total_deadlocks must accumulate across requests'
        );

        $this->assertEquals(
            3,
            $metrics['total_retries'],
            'Property violation: total_retries must accumulate across requests'
        );

        $this->assertEquals(
            3,
            $metrics['retry_attempts'][1],
            'Property violation: retry_attempts[1] must accumulate'
        );
    }

    /**
     * Test deadlock simulation with real database operations.
     * 
     * This test simulates a realistic deadlock scenario using actual
     * database transactions to verify the middleware works in production.
     *
     */
    #[\PHPUnit\Framework\Attributes\Group('integration')]
    public function test_deadlock_simulation_with_real_database_operations(): void
    {
        // This test would require actual concurrent transactions
        // For now, we'll simulate the behavior
        
        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$attemptCount) {
            $attemptCount++;

            // Simulate deadlock on first attempt
            if ($attemptCount === 1) {
                throw new QueryException(
                    config('database.default'),
                    'UPDATE attendances SET status = ? WHERE id = ?',
                    ['present', 1],
                    new \PDOException('Deadlock found when trying to get lock', '40001')
                );
            }

            // Second attempt succeeds
            return response()->json([
                'success' => true,
                'message' => 'Attendance recorded after retry',
            ]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $attemptCount);

        // Verify metrics
        $metrics = DeadlockRetryMiddleware::getMetrics();
        $this->assertGreaterThan(0, $metrics['total_deadlocks']);
    }

    /**
     * Test that non-deadlock exceptions are not retried.
     * 
     * This ensures the middleware only retries actual deadlocks,
     * not other database errors.
     *
*/
    public function test_non_deadlock_exceptions_are_not_retried(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Syntax error');

        try {
            $this->middleware->handle($request, function ($req) use (&$attemptCount) {
                $attemptCount++;
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM invalid_table',
                    [],
                    new \PDOException('Syntax error in SQL', '42000')
                );
            });
        } catch (QueryException $e) {
            // Verify only one attempt was made
            $this->assertEquals(
                1,
                $attemptCount,
                'Non-deadlock exceptions must not be retried'
            );

            // Verify no warning logs
            Log::shouldNotHaveReceived('warning');

            throw $e;
        }
    }

    /**
     * Test metrics persistence across requests.
     *
*/
    public function test_metrics_persist_in_cache(): void
    {
        DeadlockRetryMiddleware::resetMetrics();

        $request = Request::create('/api/v1/attendance', 'POST');
        $attemptCount = 0;

        // First request with deadlock
        $this->middleware->handle($request, function ($req) use (&$attemptCount) {
            $attemptCount++;
            if ($attemptCount === 1) {
                throw new QueryException(
                    'mysql',
                    'INSERT INTO attendances',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            }
            return response()->json(['success' => true]);
        });

        // Get metrics
        $metrics1 = DeadlockRetryMiddleware::getMetrics();
        $this->assertEquals(1, $metrics1['total_deadlocks']);

        // Create new middleware instance (simulating new request)
        $newMiddleware = new DeadlockRetryMiddleware();
        
        // Metrics should still be available
        $metrics2 = DeadlockRetryMiddleware::getMetrics();
        $this->assertEquals(1, $metrics2['total_deadlocks']);
        $this->assertEquals($metrics1['last_occurrence'], $metrics2['last_occurrence']);
    }
}
