<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeadlockRetryMiddleware
{
    /**
     * Maximum number of retry attempts for deadlock scenarios.
     */
    public const MAX_RETRIES = 3;

    /**
     * Base delay in milliseconds for exponential backoff.
     */
    private const BASE_DELAY_MS = 100;

    /**
     * Cache key for deadlock metrics.
     */
    private const METRICS_CACHE_KEY = 'metrics:deadlock_retries';

    /**
     * Current retry count for the request.
     */
    private int $retryCount = 0;

    /**
     * Handle an incoming request with automatic deadlock retry.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $attempt = 0;

        while ($attempt < self::MAX_RETRIES) {
            try {
                return $next($request);
            } catch (QueryException $e) {
                // Check if this is a deadlock exception
                if (!$this->isDeadlockException($e)) {
                    throw $e;
                }

                $attempt++;
                $this->retryCount = $attempt;

                // If we've exhausted all retries, throw the exception
                if ($attempt >= self::MAX_RETRIES) {
                    // Track failed retry (exhausted all attempts)
                    $this->trackDeadlockMetric('exhausted', $attempt);
                    
                    Log::error('Deadlock retry limit exceeded', [
                        'attempts' => $attempt,
                        'request_id' => $request->header('X-Request-ID'),
                        'endpoint' => $request->path(),
                        'method' => $request->method(),
                        'error_code' => $this->getErrorCode($e),
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                // Calculate exponential backoff delay: 100ms, 200ms, 400ms
                $delay = self::BASE_DELAY_MS * pow(2, $attempt - 1);
                usleep($delay * 1000);

                // Track retry attempt
                $this->trackDeadlockMetric('retry', $attempt);

                Log::warning('Deadlock detected, retrying request', [
                    'attempt' => $attempt,
                    'max_retries' => self::MAX_RETRIES,
                    'delay_ms' => $delay,
                    'request_id' => $request->header('X-Request-ID'),
                    'endpoint' => $request->path(),
                    'method' => $request->method(),
                    'error_code' => $this->getErrorCode($e),
                ]);
            }
        }

        // This should never be reached, but added for type safety
        throw new \RuntimeException('Unexpected state in DeadlockRetryMiddleware');
    }

    /**
     * Determine if the exception is a deadlock exception.
     */
    private function isDeadlockException(QueryException $e): bool
    {
        // Check for deadlock in error message (works for all database drivers)
        $message = strtolower($e->getMessage());
        
        return str_contains($message, 'deadlock') 
            || str_contains($message, 'lock wait timeout');
    }

    /**
     * Extract error code from QueryException.
     */
    private function getErrorCode(QueryException $e): ?string
    {
        $previous = $e->getPrevious();
        
        if ($previous instanceof \PDOException) {
            // Try to get SQLSTATE code
            if (isset($previous->errorInfo[0])) {
                return $previous->errorInfo[0];
            }
            
            // Fallback to exception code
            return $previous->getCode();
        }
        
        return $e->getCode();
    }

    /**
     * Track deadlock retry metrics.
     * 
     * @param string $type Type of metric: 'retry', 'success', 'exhausted'
     * @param int $attemptCount Number of attempts made
     */
    private function trackDeadlockMetric(string $type, int $attemptCount): void
    {
        try {
            $metrics = Cache::get(self::METRICS_CACHE_KEY, [
                'total_deadlocks' => 0,
                'total_retries' => 0,
                'successful_retries' => 0,
                'exhausted_retries' => 0,
                'retry_attempts' => [
                    1 => 0,
                    2 => 0,
                    3 => 0,
                ],
                'last_occurrence' => null,
            ]);

            switch ($type) {
                case 'retry':
                    $metrics['total_deadlocks']++;
                    $metrics['total_retries']++;
                    if (isset($metrics['retry_attempts'][$attemptCount])) {
                        $metrics['retry_attempts'][$attemptCount]++;
                    }
                    $metrics['last_occurrence'] = now()->toIso8601String();
                    break;

                case 'exhausted':
                    $metrics['exhausted_retries']++;
                    break;

                case 'success':
                    $metrics['successful_retries']++;
                    break;
            }

            // Calculate success rate
            $totalAttempts = $metrics['successful_retries'] + $metrics['exhausted_retries'];
            $metrics['success_rate'] = $totalAttempts > 0 
                ? round(($metrics['successful_retries'] / $totalAttempts) * 100, 2) 
                : 100;

            // Store metrics for 24 hours
            Cache::put(self::METRICS_CACHE_KEY, $metrics, now()->addDay());
        } catch (\Exception $e) {
            // Silently fail - metrics tracking shouldn't break the application
            Log::debug('Failed to track deadlock metrics', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Get deadlock retry metrics.
     * 
     * @return array
     */
    public static function getMetrics(): array
    {
        return Cache::get(self::METRICS_CACHE_KEY, [
            'total_deadlocks' => 0,
            'total_retries' => 0,
            'successful_retries' => 0,
            'exhausted_retries' => 0,
            'retry_attempts' => [
                1 => 0,
                2 => 0,
                3 => 0,
            ],
            'success_rate' => 100,
            'last_occurrence' => null,
        ]);
    }

    /**
     * Reset deadlock metrics (useful for testing).
     */
    public static function resetMetrics(): void
    {
        Cache::forget(self::METRICS_CACHE_KEY);
    }

    /**
     * Get the current retry count for this request.
     * 
     * @return int
     */
    public function getRetryCount(): int
    {
        return $this->retryCount;
    }
}
