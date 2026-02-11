<?php

namespace App\Providers;

use App\Logging\CorrelatedLogger;
use App\Logging\LogContext;
use App\Services\ObservabilityService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use App\Services\ProductionMonitoringService;

class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Get slow query threshold from config (default 500ms)
     */
    protected function getSlowQueryThreshold(): int
    {
        return config('monitoring.slow_query.warning_threshold', 500);
    }

    /**
     * Get critical query threshold from config (default 2000ms)
     */
    protected function getCriticalQueryThreshold(): int
    {
        return config('monitoring.slow_query.critical_threshold', 2000);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        // Register CorrelatedLogger as singleton
        $this->app->singleton(CorrelatedLogger::class, function () {
            return new CorrelatedLogger();
        });

        // Register ObservabilityService as singleton
        $this->app->singleton(ObservabilityService::class, function () {
            return new ObservabilityService();
        });

        // Register ProductionMonitoringService as singleton
        $this->app->singleton(ProductionMonitoringService::class, function () {
            return new ProductionMonitoringService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        $this->setupDatabaseTracing();
        $this->setupQueueTracing();
        $this->setupHttpClientTracing();
    }

    /**
     * Database Query Tracing with full correlation
     */
    protected function setupDatabaseTracing(): void
    {
        DB::listen(function (QueryExecuted $query) {
            $duration = $query->time; // milliseconds

            // Build correlation context
            $context = [
                'sql' => $this->sanitizeSql($query->sql),
                'duration_ms' => round($duration, 2),
                'connection' => $query->connectionName,
                'bindings_count' => count($query->bindings),
                // Correlation chain
                'request_id' => LogContext::get('request_id'),
                'trace_id' => LogContext::get('trace_id'),
                'span_id' => LogContext::get('span_id'),
                'parent_span_id' => LogContext::get('parent_span_id'),
                'operation' => LogContext::get('current_operation'),
                // User context for per-user tracing
                'user_id' => LogContext::get('user_id'),
                'school_id' => LogContext::get('school_id'),
                'request_date' => LogContext::get('request_date'),
            ];

            // Critical: Very slow queries (configurable, default 2s)
            if ($duration > $this->getCriticalQueryThreshold()) {
                Log::channel('system')->critical('Critical: Very Slow Database Query', array_merge($context, [
                    'bindings' => $this->formatBindings($query->bindings),
                    'severity' => 'critical',
                ]));
                
                // Record to both monitoring services for metrics aggregation
                try {
                    app(ObservabilityService::class)->recordSlowQuery(
                        $query->sql, 
                        $duration, 
                        $query->connectionName
                    );
                    app(ProductionMonitoringService::class)->recordSlowQuery(
                        $query->sql,
                        $duration,
                        $query->connectionName
                    );
                } catch (\Exception $e) {
                    // Silently fail - don't break the app for metrics
                }
            }
            // Warning: Slow queries (configurable, default 500ms)
            elseif ($duration > $this->getSlowQueryThreshold()) {
                Log::channel('system')->warning('Slow Database Query', array_merge($context, [
                    'bindings' => $this->formatBindings($query->bindings),
                    'severity' => 'medium',
                ]));
                
                // Record to both monitoring services for metrics aggregation
                try {
                    app(ObservabilityService::class)->recordSlowQuery(
                        $query->sql, 
                        $duration, 
                        $query->connectionName
                    );
                    app(ProductionMonitoringService::class)->recordSlowQuery(
                        $query->sql,
                        $duration,
                        $query->connectionName
                    );
                } catch (\Exception $e) {
                    // Silently fail
                }
            }
            // Debug: All queries (only in local/development)
            elseif (config('app.debug') && config('logging.log_all_queries', false)) {
                Log::channel('system')->debug('Database Query', $context);
            }
        });
    }

    /**
     * Queue Job Tracing with correlation propagation
     */
    protected function setupQueueTracing(): void
    {
        Queue::before(function (JobProcessing $event) {
            // Extract trace context from job payload if available
            $payload = $event->job->payload();
            $traceId = $payload['trace_id'] ?? (string) \Illuminate\Support\Str::uuid();
            $parentSpanId = $payload['span_id'] ?? null;

            // Initialize context for this job
            LogContext::setMany([
                'request_id' => $payload['request_id'] ?? $traceId,
                'trace_id' => $traceId,
                'span_id' => LogContext::generateSpanId(),
                'parent_span_id' => $parentSpanId,
                'current_operation' => 'job.' . class_basename($event->job->resolveName()),
                'span_started_at' => microtime(true),
                'request_date' => now()->toDateString(),
            ]);

            // Restore user context from payload if available
            if (isset($payload['user_id'])) {
                LogContext::set('user_id', $payload['user_id']);
            }
            if (isset($payload['school_id'])) {
                LogContext::set('school_id', $payload['school_id']);
            }

            Log::channel('system')->info('Job Processing Started', [
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
                'trace_id' => $traceId,
                'parent_span_id' => $parentSpanId,
                'request_id' => LogContext::get('request_id'),
            ]);
        });

        Queue::after(function (JobProcessed $event) {
            $spanInfo = LogContext::popSpan();
            
            Log::channel('system')->info('Job Processed Successfully', [
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'duration_ms' => $spanInfo['duration_ms'] ?? null,
                'trace_id' => LogContext::get('trace_id'),
                'span_id' => $spanInfo['span_id'] ?? null,
                'request_id' => LogContext::get('request_id'),
            ]);

            LogContext::clear();
        });

        Queue::failing(function (JobFailed $event) {
            $spanInfo = LogContext::popSpan();
            
            Log::channel('system')->error('Job Failed', [
                'job' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'queue' => $event->job->getQueue(),
                'attempt' => $event->job->attempts(),
                'duration_ms' => $spanInfo['duration_ms'] ?? null,
                'trace_id' => LogContext::get('trace_id'),
                'span_id' => $spanInfo['span_id'] ?? null,
                'request_id' => LogContext::get('request_id'),
                'user_id' => LogContext::get('user_id'),
                'school_id' => LogContext::get('school_id'),
                'exception' => $event->exception->getMessage(),
                'exception_class' => get_class($event->exception),
                'exception_file' => $event->exception->getFile(),
                'exception_line' => $event->exception->getLine(),
            ]);

            // Record failed job to ProductionMonitoringService
            try {
                app(ProductionMonitoringService::class)->recordFailedJob(
                    $event->job->resolveName(),
                    $event->exception->getMessage(),
                    [
                        'job_id' => $event->job->getJobId(),
                        'queue' => $event->job->getQueue(),
                        'attempt' => $event->job->attempts(),
                        'exception_class' => get_class($event->exception),
                        'user_id' => LogContext::get('user_id'),
                        'school_id' => LogContext::get('school_id'),
                    ]
                );
            } catch (\Exception $e) {
                // Silently fail
            }

            LogContext::clear();
        });
    }

    /**
     * HTTP Client Tracing for external API calls
     */
    protected function setupHttpClientTracing(): void
    {
        Event::listen(function (RequestSending $event) {
            $spanId = LogContext::pushSpan('http.external.' . parse_url($event->request->url(), PHP_URL_HOST));

            Log::channel('system')->info('Outgoing HTTP Request', [
                'url' => $event->request->url(),
                'method' => $event->request->method(),
                'span_id' => $spanId,
                'trace_id' => LogContext::get('trace_id'),
                'request_id' => LogContext::get('request_id'),
            ]);
        });

        Event::listen(function (ResponseReceived $event) {
            $spanInfo = LogContext::popSpan();
            $status = $event->response->status();

            $level = $status >= 500 ? 'error' : ($status >= 400 ? 'warning' : 'info');

            Log::channel('system')->log($level, 'Outgoing HTTP Response', [
                'url' => $event->request->url(),
                'method' => $event->request->method(),
                'status' => $status,
                'duration_ms' => $spanInfo['duration_ms'] ?? null,
                'span_id' => $spanInfo['span_id'] ?? null,
                'trace_id' => LogContext::get('trace_id'),
                'request_id' => LogContext::get('request_id'),
            ]);
        });

        Event::listen(function (ConnectionFailed $event) {
            $spanInfo = LogContext::popSpan();
            
            Log::channel('system')->error('Outgoing HTTP Connection Failed', [
                'url' => $event->request->url(),
                'method' => $event->request->method(),
                'duration_ms' => $spanInfo['duration_ms'] ?? null,
                'span_id' => $spanInfo['span_id'] ?? null,
                'trace_id' => LogContext::get('trace_id'),
                'request_id' => LogContext::get('request_id'),
                'user_id' => LogContext::get('user_id'),
            ]);
        });
    }

    /**
     * Sanitize SQL to remove sensitive data
     */
    protected function sanitizeSql(string $sql): string
    {
        // Truncate very long queries
        if (strlen($sql) > 500) {
            return substr($sql, 0, 500) . '...[truncated]';
        }
        return $sql;
    }

    /**
     * Format bindings safely for logging
     */
    protected function formatBindings(array $bindings): array
    {
        return array_map(function ($binding) {
            // Mask potentially sensitive data
            if (is_string($binding)) {
                // Mask emails
                if (filter_var($binding, FILTER_VALIDATE_EMAIL)) {
                    return '[email]';
                }
                // Mask long strings (potential passwords/tokens)
                if (strlen($binding) > 50) {
                    return substr($binding, 0, 10) . '...[masked]';
                }
            }
            return $binding;
        }, $bindings);
    }
}
