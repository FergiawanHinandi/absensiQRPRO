<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

/**
 * Aggregates multiple health checks into a single system health report.
 *
 * Overall status is UNHEALTHY if any critical check fails.
 */
class HealthCheckAggregator
{
    /** @var HealthCheckInterface[] */
    private array $checks = [];

    /**
     * @param  iterable<HealthCheckInterface>  $checks
     */
    public function __construct(iterable $checks = [])
    {
        foreach ($checks as $check) {
            $this->checks[] = $check;
        }
    }

    /**
     * Register an additional health check.
     */
    public function addCheck(HealthCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    /**
     * Run all health checks and return aggregated result.
     */
    public function run(): array
    {
        $results = [];
        $overallHealthy = true;
        $totalStart = microtime(true);

        foreach ($this->checks as $check) {
            $result = $check->check();

            $results[$check->name()] = array_merge(
                $result->toArray(),
                ['critical' => $check->isCritical()]
            );

            // Only critical checks affect overall status
            if ($check->isCritical() && ! $result->isHealthy()) {
                $overallHealthy = false;
            }
        }

        $totalTime = round((microtime(true) - $totalStart) * 1000, 2);

        return [
            'status' => $overallHealthy ? 'healthy' : 'unhealthy',
            'total_time_ms' => $totalTime,
            'checks' => $results,
            'meta' => [
                'php_version' => PHP_VERSION,
                'laravel_version' => app()->version(),
                'environment' => config('app.env'),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Quick check — returns true/false without details.
     */
    public function isHealthy(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->isCritical() && ! $check->check()->isHealthy()) {
                return false;
            }
        }

        return true;
    }
}
