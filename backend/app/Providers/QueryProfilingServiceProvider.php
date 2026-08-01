<?php

namespace App\Providers;

use App\Services\QueryProfilingService;
use Illuminate\Support\ServiceProvider;

class QueryProfilingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(QueryProfilingService::class, function ($app) {
            return new QueryProfilingService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if (config('query_profiling.enabled', false)) {
            $profiler = $this->app->make(QueryProfilingService::class);
            $profiler->start();
        }
    }
}
