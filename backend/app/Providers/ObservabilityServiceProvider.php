<?php

namespace App\Providers;

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

class ObservabilityServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // 1. Database Query Tracing
        // Logs slow queries with Trace ID correlation
        DB::listen(function (QueryExecuted $query) {
            $duration = $query->time; // milliseconds

            // Threshold for "Slow Query"
            if ($duration > 1000) {
                Log::warning('Slow Database Query', [
                    'sql' => $query->sql,
                    'duration_ms' => $duration,
                    'trace_id' => Context::get('trace_id'), // Correlated!
                    'connection' => $query->connectionName,
                    'bindings' => $this->formatBindings($query->bindings)
                ]);
            }
        });

        // 2. Queue Tracing
        // Propagate Context logic would go here if using a full library.
        // For now, we ensure Job logs include current context if available.

        Queue::before(function (JobProcessing $event) {
            // Unpack trace_id from payload if we packed it there? 
            // Or generate new one if missing (async job started from scratch)
            $traceId = $event->job->payload()['trace_id'] ?? (string) \Illuminate\Support\Str::uuid();
            Context::add('trace_id', $traceId);
            Context::add('job_id', $event->job->getJobId());

            Log::info('Job Processing Started', [
                'job' => $event->job->resolveName(),
                'trace_id' => $traceId
            ]);
        });

        Queue::after(function (JobProcessed $event) {
            Log::info('Job Processed Successfully', [
                'job' => $event->job->resolveName(),
                'trace_id' => Context::get('trace_id')
            ]);
        });

        Queue::failing(function (JobFailed $event) {
            Log::error('Job Failed', [
                'job' => $event->job->resolveName(),
                'trace_id' => Context::get('trace_id'),
                'exception' => $event->exception->getMessage()
            ]);
        });

        // 3. HTTP Client Tracing (External Calls)
        // Inject Traceparent header into outgoing requests
        Event::listen(function (RequestSending $event) {
            $traceId = Context::get('trace_id') ?? (string) \Illuminate\Support\Str::uuid();
            // $event->request->withHeader('X-Trace-ID', $traceId);
            // Note: Illuminate\Http\Client\Request does not have withHeader method.
            // Headers should be set before sending the request.
            // $event->request->withHeader('traceparent', "00-{$traceId}-...");

            Log::info('Outgoing HTTP Request', [
                'url' => $event->request->url(),
                'method' => $event->request->method(),
                'trace_id' => $traceId
            ]);
        });

        Event::listen(function (ConnectionFailed $event) {
            Log::error('Outgoing HTTP Connection Failed', [
                'url' => $event->request->url(),
                'trace_id' => Context::get('trace_id')
            ]);
        });
    }

    private function formatBindings($bindings)
    {
        // Safety: Don't log sensitive data in bindings if possible, or truncate
        return array_map(function ($binding) {
            if (is_string($binding) && strlen($binding) > 50) {
                return substr($binding, 0, 50) . '...';
            }
            return $binding;
        }, $bindings);
    }
}
