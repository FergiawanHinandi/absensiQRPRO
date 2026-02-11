<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeadlockRetryMiddleware
{
    /**
     * Maximum number of retry attempts for deadlock scenarios.
     */
    private const MAX_RETRIES = 3;

    /**
     * Base delay in milliseconds for exponential backoff.
     */
    private const BASE_DELAY_MS = 100;

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

                // If we've exhausted all retries, throw the exception
                if ($attempt >= self::MAX_RETRIES) {
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
}
