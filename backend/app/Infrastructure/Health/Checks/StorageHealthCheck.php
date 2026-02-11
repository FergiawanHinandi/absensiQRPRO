<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Infrastructure\Health\HealthCheckInterface;
use App\Infrastructure\Health\HealthStatus;
use Illuminate\Support\Facades\Storage;

class StorageHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'storage';
    }

    public function check(): HealthStatus
    {
        $start = microtime(true);

        try {
            $testFile = 'health_check_' . time() . '.tmp';
            Storage::put($testFile, 'ok');
            $content = Storage::get($testFile);
            Storage::delete($testFile);

            $responseTime = (microtime(true) - $start) * 1000;

            if ($content !== 'ok') {
                return HealthStatus::warning($responseTime, [
                    'message' => 'Storage read/write mismatch',
                ]);
            }

            return HealthStatus::ok($responseTime);
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
