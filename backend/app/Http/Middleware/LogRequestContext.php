<?php

namespace App\Http\Middleware;

use App\Logging\LogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to capture request context for centralized logging
 * 
 * Initializes LogContext at request start and logs request completion.
 */
class LogRequestContext
{
    /**
     * Endpoints to exclude from logging (health checks, metrics)
     */
    protected array $excludedEndpoints = [
        'health',
        'health/*',
        'metrics',
        'metrics/*',
        'up',
        '_debugbar/*',
        'telescope/*',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        // Initialize log context from request
        LogContext::initFromRequest($request);

        // Set request ID header for tracing
        $requestId = LogContext::get('request_id');
        
        // Process request
        $response = $next($request);

        // Calculate response time
        $responseTimeMs = (microtime(true) - $startTime) * 1000;
        
        // Update context with response info
        LogContext::setResponse($response->getStatusCode(), $responseTimeMs);

        // Set user context if authenticated
        if ($user = $request->user()) {
            LogContext::setUser($user);
        }

        // Add request ID to response headers
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Response-Time', round($responseTimeMs, 2) . 'ms');

        // Log request completion (unless excluded)
        if (!$this->shouldExclude($request)) {
            $this->logRequestCompletion($request, $response, $responseTimeMs);
        }

        return $response;
    }

    /**
     * Clean up context after response is sent
     */
    public function terminate(Request $request, Response $response): void
    {
        LogContext::clear();
    }

    /**
     * Log the completed request
     */
    protected function logRequestCompletion(Request $request, Response $response, float $responseTimeMs): void
    {
        $statusCode = $response->getStatusCode();
        $level = $this->determineLogLevel($statusCode, $responseTimeMs);

        $message = sprintf(
            '%s %s %d %.2fms',
            $request->method(),
            LogContext::get('endpoint'),
            $statusCode,
            $responseTimeMs
        );

        Log::channel('request')->log($level, $message, [
            'request_body_size' => strlen($request->getContent()),
            'response_body_size' => strlen($response->getContent()),
            'is_ajax' => $request->ajax(),
            'is_api' => $request->is('api/*'),
        ]);

        // Detect and log slow requests (> 1.5s)
        if ($responseTimeMs > 1500) {
            Log::warning('Slow API Request Detected', [
                'url' => $request->fullUrl(),
                'method' => $request->method(),
                'duration_ms' => round($responseTimeMs, 2),
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        }
    }

    /**
     * Determine log level based on response
     */
    protected function determineLogLevel(int $statusCode, float $responseTimeMs): string
    {
        // Server errors
        if ($statusCode >= 500) {
            return 'error';
        }

        // Client errors
        if ($statusCode >= 400) {
            return 'warning';
        }

        // Slow requests (> 1 second)
        if ($responseTimeMs > 1000) {
            return 'warning';
        }

        return 'info';
    }

    /**
     * Check if endpoint should be excluded from logging
     */
    protected function shouldExclude(Request $request): bool
    {
        foreach ($this->excludedEndpoints as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }
        return false;
    }
}
