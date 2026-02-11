<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Bus\CommandBus;
use App\Application\Bus\CommandBusInterface;
use App\Application\Bus\Middleware\LoggingMiddleware;
use App\Application\Bus\Middleware\TransactionMiddleware;
use App\Application\Bus\Middleware\ValidationMiddleware;
use App\Application\Commands\Attendance\ApproveAttendanceCommand;
use App\Application\Commands\Attendance\CheckOutCommand;
use App\Application\Commands\Attendance\RecordAttendanceCommand;
use App\Application\Commands\Attendance\RequestCorrectionCommand;
use App\Application\Commands\QR\GenerateQRCommand;
use App\Application\Commands\Subscription\ExtendSubscriptionCommand;
use App\Application\Guards\SubscriptionPolicyGuard;
use App\Application\Handlers\Attendance\ApproveAttendanceHandler;
use App\Application\Handlers\Attendance\CheckOutHandler;
use App\Application\Handlers\Attendance\RecordAttendanceHandler;
use App\Application\Handlers\Attendance\RequestCorrectionHandler;
use App\Application\Handlers\QR\GenerateQRHandler;
use App\Application\Handlers\Subscription\ExtendSubscriptionHandler;
use App\Application\Projectors\AttendanceSummaryProjector;
use App\Application\Projectors\SchoolStatisticsProjector;
use App\Application\Queries\Contracts\DashboardQueryInterface;
use App\Application\Queries\CountBasedDashboardQuery;
use App\Application\Queries\SchoolDashboardQuery;
use App\Infrastructure\Exceptions\ExceptionMapper;
use App\Infrastructure\Health\Checks\DatabaseHealthCheck;
use App\Infrastructure\Health\Checks\DiskHealthCheck;
use App\Infrastructure\Health\Checks\QueueHealthCheck;
use App\Infrastructure\Health\Checks\RedisHealthCheck;
use App\Infrastructure\Health\Checks\StorageHealthCheck;
use App\Infrastructure\Health\HealthCheckAggregator;
use App\Infrastructure\Persistence\AttendanceRepository;
use App\Infrastructure\Persistence\AttendanceSummaryReadModel;
use App\Infrastructure\Queue\SchoolIsolatedDispatcher;
use App\Infrastructure\Redis\RedisCircuitBreaker;
use App\Services\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class DomainServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Tenant Context (request-scoped singleton) ───────────────
        $this->app->scoped(TenantContext::class);

        // ── Command Bus ─────────────────────────────────────────────
        $this->app->singleton(CommandBusInterface::class, function ($app) {
            $bus = new CommandBus($app);

            // Middleware pipeline: Logging → Validation → Transaction → Handler
            $bus->addMiddleware(LoggingMiddleware::class);
            $bus->addMiddleware(ValidationMiddleware::class);
            $bus->addMiddleware(TransactionMiddleware::class);

            // Register command → handler mappings
            $bus->map([
                RecordAttendanceCommand::class => RecordAttendanceHandler::class,
                CheckOutCommand::class => CheckOutHandler::class,
                RequestCorrectionCommand::class => RequestCorrectionHandler::class,
                ApproveAttendanceCommand::class => ApproveAttendanceHandler::class,
                GenerateQRCommand::class => GenerateQRHandler::class,
                ExtendSubscriptionCommand::class => ExtendSubscriptionHandler::class,
            ]);

            return $bus;
        });

        // ── Infrastructure Services ─────────────────────────────────
        $this->app->singleton(RedisCircuitBreaker::class);
        $this->app->singleton(SchoolIsolatedDispatcher::class);
        $this->app->singleton(ExceptionMapper::class);

        // ── Persistence ─────────────────────────────────────────────
        $this->app->singleton(AttendanceRepository::class);
        $this->app->singleton(AttendanceSummaryReadModel::class);

        // ── Guards ──────────────────────────────────────────────────
        $this->app->singleton(SubscriptionPolicyGuard::class);

        // ── Dashboard Query (feature-flagged) ───────────────────────
        // Rollback: set FEATURE_USE_READ_MODEL=false → instant fallback
        $this->app->singleton(DashboardQueryInterface::class, function ($app) {
            if (config('features.use_read_model', false)) {
                return $app->make(SchoolDashboardQuery::class);
            }

            return $app->make(CountBasedDashboardQuery::class);
        });

        // ── Health Checks ───────────────────────────────────────────
        $this->app->tag([
            DatabaseHealthCheck::class,
            RedisHealthCheck::class,
            QueueHealthCheck::class,
            StorageHealthCheck::class,
            DiskHealthCheck::class,
        ], 'health.checks');

        $this->app->singleton(HealthCheckAggregator::class, function ($app) {
            return new HealthCheckAggregator($app->tagged('health.checks'));
        });
    }

    public function boot(): void
    {
        // ── Register Projector Subscribers (guarded by feature flag) ─
        // Projectors stay enabled even during rollback so the read model
        // keeps receiving updates, ready for a fast re-switch.
        if (config('features.projectors_enabled', true)) {
            Event::subscribe(AttendanceSummaryProjector::class);
            Event::subscribe(SchoolStatisticsProjector::class);
        }
    }
}
