<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\DeadlockRetryMiddleware;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit tests for DeadlockRetryMiddleware
 *
 * Tests automatic retry logic with exponential backoff for database deadlocks.
 *
 * @group middleware
 * @group concurrency
 * @group deadlock
 */
class DeadlockRetryMiddlewareTest extends TestCase
{
    protected DeadlockRetryMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();

        $this->middleware = new DeadlockRetryMiddleware();
        Log::spy();
    }

    /**
     * @test
     */
    public function successful_request_passes_through_without_retry(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $callCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$callCount) {
            $callCount++;
            return new JsonResponse(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(1, $callCount, 'Request should only be called once');
        Log::shouldNotHaveReceived('warning');
    }

    /**
     * @test
     */
    public function mysql_deadlock_triggers_retry(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $request->headers->set('X-Request-ID', 'test-request-123');
        $callCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$callCount) {
            $callCount++;

            // First call throws deadlock, second succeeds
            if ($callCount === 1) {
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    new \PDOException('Deadlock found when trying to get lock', '40001')
                );
            }

            return new JsonResponse(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $callCount, 'Request should be retried once');

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Deadlock detected, retrying request', \Mockery::on(function ($context) {
                return $context['attempt'] === 1
                    && $context['max_retries'] === 3
                    && $context['delay_ms'] === 100
                    && $context['request_id'] === 'test-request-123';
            }));
    }

    /**
     * @test
     */
    public function postgresql_deadlock_triggers_retry(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $callCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$callCount) {
            $callCount++;

            // First call throws PostgreSQL deadlock, second succeeds
            if ($callCount === 1) {
                $pdoException = new \PDOException('deadlock detected');
                $pdoException->errorInfo = ['40P01', 1, 'deadlock detected'];
                throw new QueryException(
                    'pgsql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    $pdoException
                );
            }

            return new JsonResponse(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $callCount, 'Request should be retried once');
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * @test
     */
    public function exponential_backoff_increases_delay(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $callCount = 0;
        $delays = [];

        try {
            $this->middleware->handle($request, function ($req) use (&$callCount, &$delays) {
                $callCount++;

                // Always throw deadlock to test all retries
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            });
        } catch (QueryException $e) {
            // Expected to throw after max retries
        }

        $this->assertEquals(3, $callCount, 'Should attempt 3 times before giving up');

        // Verify exponential backoff delays were logged: 100ms, 200ms
        Log::shouldHaveReceived('warning')
            ->twice()
            ->with('Deadlock detected, retrying request', \Mockery::on(function ($context) use (&$delays) {
                $delays[] = $context['delay_ms'];
                return true;
            }));

        $this->assertEquals([100, 200], $delays, 'Delays should follow exponential backoff: 100ms, 200ms');
    }

    /**
     * @test
     */
    public function max_retries_exhausted_throws_exception(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $request->headers->set('X-Request-ID', 'test-request-456');
        $callCount = 0;

        try {
            $this->middleware->handle($request, function ($req) use (&$callCount) {
                $callCount++;
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            });

            $this->fail('Expected QueryException to be thrown');
        } catch (QueryException $e) {
            // Verify max attempts reached
            $this->assertEquals(3, $callCount, 'Should attempt exactly 3 times');

            // Verify error log was called
            Log::shouldHaveReceived('error')
                ->once()
                ->with('Deadlock retry limit exceeded', \Mockery::on(function ($context) {
                    return $context['attempts'] === 3
                        && $context['request_id'] === 'test-request-456'
                        && $context['error_code'] === '40001';
                }));

            // Verify warning logs were called for retries (2 times: attempt 1 and 2)
            Log::shouldHaveReceived('warning')
                ->twice();
        }
    }

    /**
     * @test
     */
    public function non_deadlock_exception_not_retried(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $callCount = 0;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('Syntax error');

        try {
            $this->middleware->handle($request, function ($req) use (&$callCount) {
                $callCount++;
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM invalid_table',
                    [],
                    new \PDOException('Syntax error', '42000')
                );
            });
        } catch (QueryException $e) {
            $this->assertEquals(1, $callCount, 'Non-deadlock exceptions should not be retried');
            Log::shouldNotHaveReceived('warning');
            throw $e;
        }
    }

    /**
     * @test
     */
    public function deadlock_message_detection_works(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $callCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$callCount) {
            $callCount++;

            // First call throws exception with "deadlock" in message
            if ($callCount === 1) {
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    new \PDOException('Transaction deadlock detected', '99999')
                );
            }

            return new JsonResponse(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $callCount, 'Should retry based on message content');
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * @test
     */
    public function lock_wait_timeout_triggers_retry(): void
    {
        $request = Request::create('/api/v1/attendance', 'POST');
        $callCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$callCount) {
            $callCount++;

            // First call throws lock wait timeout
            if ($callCount === 1) {
                $pdoException = new \PDOException('Lock wait timeout exceeded');
                $pdoException->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    $pdoException
                );
            }

            return new JsonResponse(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $callCount, 'Lock wait timeout should trigger retry');
        Log::shouldHaveReceived('warning')->once();
    }

    /**
     * @test
     */
    public function retry_logs_include_request_context(): void
    {
        $request = Request::create('/api/v1/attendance/scan', 'POST');
        $request->headers->set('X-Request-ID', 'req-789');
        $callCount = 0;

        $response = $this->middleware->handle($request, function ($req) use (&$callCount) {
            $callCount++;

            if ($callCount === 1) {
                throw new QueryException(
                    'mysql',
                    'SELECT * FROM attendances FOR UPDATE',
                    [],
                    new \PDOException('Deadlock found', '40001')
                );
            }

            return new JsonResponse(['success' => true]);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals(2, $callCount);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with('Deadlock detected, retrying request', \Mockery::on(function ($context) {
                return $context['request_id'] === 'req-789'
                    && $context['endpoint'] === 'api/v1/attendance/scan'
                    && $context['method'] === 'POST'
                    && $context['error_code'] === '40001';
            }));
    }
}
