<?php

namespace App\Http\Middleware;

use App\Services\PrometheusMetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to collect request metrics for Prometheus
 */
class CollectMetrics
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        $duration = microtime(true) - $startTime;

        // Record metrics
        $endpoint = $this->normalizeEndpoint($request);
        
        PrometheusMetricsService::recordRequest(
            method: $request->method(),
            endpoint: $endpoint,
            status: $response->getStatusCode(),
            duration: $duration
        );

        return $response;
    }

    /**
     * Normalize endpoint to avoid cardinality explosion
     * 
     * Replaces dynamic segments (IDs, UUIDs) with placeholders
     */
    protected function normalizeEndpoint(Request $request): string
    {
        $path = $request->path();

        // Replace UUIDs
        $path = preg_replace(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
            '{uuid}',
            $path
        );

        // Replace numeric IDs
        $path = preg_replace('/\/\d+/', '/{id}', $path);

        // Limit path length
        if (strlen($path) > 50) {
            $path = substr($path, 0, 50) . '...';
        }

        return '/' . ltrim($path, '/');
    }
}
