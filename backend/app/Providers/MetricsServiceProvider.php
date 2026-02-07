<?php

namespace App\Providers;

use App\Services\PrometheusMetricsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessing;

class MetricsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PrometheusMetricsService::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (!config('metrics.enabled', true)) {
            return;
        }

        $this->registerDatabaseQueryListener();
        $this->registerQueueListeners();
    }

    /**
     * Register database query listener for metrics
     */
    protected function registerDatabaseQueryListener(): void
    {
        DB::listen(function ($query) {
            PrometheusMetricsService::recordQuery($query->time);
        });
    }

    /**
     * Register queue event listeners for metrics
     */
    protected function registerQueueListeners(): void
    {
        // Track job start time
        Queue::before(function (JobProcessing $event) {
            $event->job->startTime = microtime(true);
        });

        // Record successful job
        Queue::after(function (JobProcessed $event) {
            $duration = isset($event->job->startTime) 
                ? (microtime(true) - $event->job->startTime) * 1000 
                : 0;

            PrometheusMetricsService::recordJob(
                job: $event->job->resolveName(),
                durationMs: $duration,
                failed: false
            );
        });

        // Record failed job
        Queue::failing(function (JobFailed $event) {
            $duration = isset($event->job->startTime) 
                ? (microtime(true) - $event->job->startTime) * 1000 
                : 0;

            PrometheusMetricsService::recordJob(
                job: $event->job->resolveName(),
                durationMs: $duration,
                failed: true
            );
        });
    }
}
