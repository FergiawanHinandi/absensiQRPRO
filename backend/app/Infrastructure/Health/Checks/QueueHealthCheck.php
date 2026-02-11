<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Infrastructure\Health\HealthCheckInterface;
use App\Infrastructure\Health\HealthStatus;
use Illuminate\Support\Facades\DB;

class QueueHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'queue';
    }

    public function check(): HealthStatus
    {
        $start = microtime(true);

        try {
            $failedCount = DB::table('failed_jobs')->count();
            $pendingCount = DB::table('jobs')->count();

            $responseTime = (microtime(true) - $start) * 1000;

            $details = [
                'pending_jobs' => $pendingCount,
                'failed_jobs' => $failedCount,
                'connection' => config('queue.default'),
            ];

            if ($failedCount > 100) {
                return HealthStatus::warning($responseTime, array_merge($details, [
                    'message' => 'High number of failed jobs',
                ]));
            }

            if ($pendingCount > 10000) {
                return HealthStatus::warning($responseTime, array_merge($details, [
                    'message' => 'Large queue backlog',
                ]));
            }

            return HealthStatus::ok($responseTime, $details);
        } catch (\Throwable $e) {
            $responseTime = (microtime(true) - $start) * 1000;

            return HealthStatus::error($responseTime, [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function isCritical(): bool
    {
        return false;
    }
}
