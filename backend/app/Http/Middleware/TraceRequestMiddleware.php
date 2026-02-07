<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TraceRequestMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Get or Generate Trace ID / Request ID
        // Compatible with W3C Trace Context (traceparent) if present, otherwise UUID
        $traceId = $request->header('traceparent')
            ? $this->parseTraceParent($request->header('traceparent'))
            : (string) Str::uuid();

        // 2. Set correlation ID in shared context (for Logs)
        Context::add('trace_id', $traceId);

        // Also capture user ID if authenticated (will be updated later in pipeline, but init here)
        // Context::add('user_id', $request->user()?->id ?? 'guest');

        // 3. Start Timer
        $startTime = microtime(true);

        // 4. Record Request Start (Span Start)
        // In a real OTel setup, $span = Tracer::startSpan('http_request');

        $response = $next($request);

        // 5. Calculate Duration
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        // 6. Record Metrics for Alerting (Cheap implementation via Cache/Redis)
        $this->recordMetrics($response->status(), $durationMs);

        // 7. Add Trace ID to Response Header
        $response->headers->set('X-Trace-ID', $traceId);

        // 8. Log based on Thresholds (Structured Log for Tracing)
        $logContext = [
            'trace_id' => $traceId,
            'method' => $request->method(),
            'uri' => $request->path(),
            'status' => $response->status(),
            'duration_ms' => $durationMs,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        if ($response->status() >= 500) {
            Log::error('HTTP Request Failed', $logContext);
        } elseif ($durationMs > 2000) {
            Log::warning('Slow HTTP Request Detected', $logContext);
        } else {
            // Debug level for normal traces to avoid spamming production logs
            Log::debug('HTTP Request Handled', $logContext);
        }

        return $response;
    }

    private function startTrace()
    {
        // Placeholder for OpenTelemetry SDK Hook
    }

    private function parseTraceParent($header)
    {
        // Basic parsing of W3C traceparent to extract Trace ID
        // 00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01
        $parts = explode('-', $header);
        return $parts[1] ?? (string) Str::uuid();
    }

    private function recordMetrics($status, $duration)
    {
        try {
            // Use Redis to store ephemeral buckets for the Alerting Command
            $key = 'metrics:http:' . now()->format('Hi'); // Group by minute

            // Increment Total Requests
            \Illuminate\Support\Facades\Redis::incr("$key:total");

            // Increment Errors if 5xx
            if ($status >= 500) {
                \Illuminate\Support\Facades\Redis::incr("$key:errors");
            }

            // Track slow requests
            if ($duration > 2000) {
                \Illuminate\Support\Facades\Redis::incr("$key:slow");
                // Persist detailed slow log for analysis?
            }

            // Set expiry for metric keys (1 hour)
            \Illuminate\Support\Facades\Redis::expire("$key:total", 3600);
            \Illuminate\Support\Facades\Redis::expire("$key:errors", 3600);
            \Illuminate\Support\Facades\Redis::expire("$key:slow", 3600);
        } catch (\Exception $e) {
            // Fail silently, don't crash request for metrics
        }
    }
}
