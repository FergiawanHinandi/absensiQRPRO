<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Infrastructure\Health\HealthCheckInterface;
use App\Infrastructure\Health\HealthStatus;

class DiskHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'disk';
    }

    public function check(): HealthStatus
    {
        $start = microtime(true);

        try {
            $path = storage_path();
            $totalSpace = disk_total_space($path);
            $freeSpace = disk_free_space($path);

            $responseTime = (microtime(true) - $start) * 1000;

            if ($totalSpace === false || $freeSpace === false) {
                return HealthStatus::error($responseTime, [
                    'message' => 'Unable to determine disk space',
                ]);
            }

            $usagePercent = round((1 - $freeSpace / $totalSpace) * 100, 2);

            $details = [
                'total_gb' => round($totalSpace / 1024 / 1024 / 1024, 2),
                'free_gb' => round($freeSpace / 1024 / 1024 / 1024, 2),
                'usage_percent' => $usagePercent,
            ];

            if ($usagePercent > 95) {
                return HealthStatus::error($responseTime, array_merge($details, [
                    'message' => 'Disk space critically low',
                ]));
            }

            if ($usagePercent > 85) {
                return HealthStatus::warning($responseTime, array_merge($details, [
                    'message' => 'Disk space running low',
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
        return true;
    }
}
