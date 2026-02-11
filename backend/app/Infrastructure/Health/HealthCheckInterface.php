<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

interface HealthCheckInterface
{
    /**
     * Get the name of this health check.
     */
    public function name(): string;

    /**
     * Run the health check.
     */
    public function check(): HealthStatus;

    /**
     * Whether this check is critical (failure = system unhealthy).
     */
    public function isCritical(): bool;
}
