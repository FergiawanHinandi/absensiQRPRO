<?php

declare(strict_types=1);

namespace App\Infrastructure\Health\Checks;

use App\Infrastructure\Health\HealthCheckInterface;
use App\Infrastructure\Health\HealthStatus;
use Illuminate\Support\Facades\Redis;

class RedisHealthCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'redis';
    }

    public function check(): HealthStatus
    {
        $start = microtime(true);

        try {
            $testKey = 'health_check:' . time();
            Redis::set($testKey, 'ok', 'EX', 10);
            $value = Redis::get($testKey);
            Redis::del($testKey);

            $responseTime = (microtime(true) - $start) * 1000;

            if ($value !== 'ok') {
                return HealthStatus::warning($responseTime, [
                    'message' => 'Redis read/write mismatch',
                ]);
            }

            if ($responseTime > 100) {
                return HealthStatus::warning($responseTime, [
                    'message' => 'Redis is slow',
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
        return false; // Redis failure is handled by circuit breaker
    }
}
