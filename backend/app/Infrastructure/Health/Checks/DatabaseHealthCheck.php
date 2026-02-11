<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Infrastructure\Health\HealthCheckInterface;
use App\Infrastructure\Health\HealthStatus;
use Illuminate\Support\Facades\DB;

class DatabaseHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'database';
    }

    public function check(): HealthStatus
    {
        $start = microtime(true);

        try {
            DB::select('SELECT 1');
            $responseTime = (microtime(true) - $start) * 1000;

            if ($responseTime > 1000) {
                return HealthStatus::warning($responseTime, [
                    'message' => 'Database is slow',
                    'connection' => config('database.default'),
                ]);
            }

            return HealthStatus::ok($responseTime, [
                'connection' => config('database.default'),
            ]);
        } catch (\Throwable $e) {
            $responseTime = (microtime(true) - $start) * 1000;

            return HealthStatus::error($responseTime, [
                'error' => $e->getMessage(),
                'connection' => config('database.default'),
            ]);
        }
    }

    public function isCritical(): bool
    {
        return true;
    }
}
