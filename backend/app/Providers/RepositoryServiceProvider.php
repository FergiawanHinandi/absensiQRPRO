<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Attendance Domain
        $this->app->bind(
            \App\Core\Domain\Repositories\AttendanceRepositoryInterface::class,
            \App\Infrastructure\Repositories\EloquentAttendanceRepository::class
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
