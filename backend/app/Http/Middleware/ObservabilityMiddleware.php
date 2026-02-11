<?php

namespace App\Http\Middleware;

use App\Services\ObservabilityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that tracks request/response metrics for observability
 * 
 * Tracks:
 * - Response time (alerts if >2 seconds)
 * - Error rate (tracks 4xx/5xx responses)
 * - Total request count for error rate calculation
 */
class ObservabilityMiddleware
{
    private ObservabilityService $observability;

    public function __construct(ObservabilityService $observability)
    {
        $this->observability = $observability;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Record start time
        $startTime = microtime(true);

        // Record this request for error rate calculation
        $this->observability->recordRequest();

        // Process request
        $response = $next($request);

        // Calculate duration
        $durationMs = (microtime(true) - $startTime) * 1000;

        // Get endpoint identifier
        $endpoint = $this->getEndpointIdentifier($request);
        $statusCode = $response->getStatusCode();

        // Record response time (will alert if slow)
        $this->observability->recordResponseTime($endpoint, $durationMs, $statusCode);

        // Record errors
        if ($statusCode >= 400) {
            $errorMessage = null;
            if ($statusCode >= 500) {
                $content = $response->getContent();
                $decoded = json_decode($content, true);
                $errorMessage = $decoded['message'] ?? substr($content, 0, 200);
            }
            $this->observability->recordError($endpoint, $statusCode, $errorMessage);
        }

        // Add timing header for debugging
        if (config('app.debug')) {
            $response->headers->set('X-Response-Time', sprintf('%.2fms', $durationMs));
        }

        return $response;
    }

    /**
     * Get a normalized endpoint identifier for grouping
     */
    private function getEndpointIdentifier(Request $request): string
    {
        $route = $request->route();
        
        if ($route) {
            // Use route name if available
            if ($name = $route->getName()) {
                return $name;
            }
            
            // Use route action
            $action = $route->getActionName();
            if ($action && $action !== 'Closure') {
                return $action;
            }
        }

        // Fallback: method + path (with IDs normalized)
        $path = preg_replace('/\/\d+/', '/{id}', $request->path());
        return $request->method() . ' /' . $path;
    }
}
