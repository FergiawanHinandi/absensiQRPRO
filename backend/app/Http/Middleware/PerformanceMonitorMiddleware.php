<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PerformanceMonitorMiddleware
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip if not testing/local environment to save overhead
        if (!app()->isLocal() && !app()->runningUnitTests()) {
            return $next($request);
        }

        DB::enableQueryLog();
        $startMemory = memory_get_usage();
        $startTime = microtime(true);

        $response = $next($request);

        $endMemory = memory_get_usage();
        $duration = (microtime(true) - $startTime) * 1000;
        $queryCount = count(DB::getQueryLog());
        $memoryPeak = memory_get_peak_usage(true);

        // Add metrics to Response Headers
        $response->headers->set('X-Perf-Query-Count', $queryCount);
        $response->headers->set('X-Perf-Memory-Usage', $this->formatBytes($endMemory - $startMemory));
        $response->headers->set('X-Perf-Memory-Peak', $this->formatBytes($memoryPeak));
        $response->headers->set('X-Perf-Time', round($duration, 2) . 'ms');

        return $response;
    }

    private function formatBytes($size, $precision = 2)
    {
        $base = log($size, 1024);
        $suffixes = array('', 'KB', 'MB', 'GB', 'TB');   
        return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
    }
}
