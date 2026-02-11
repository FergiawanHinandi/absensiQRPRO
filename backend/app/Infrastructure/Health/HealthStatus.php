<?php

declare(strict_types=1);

namespace App\Infrastructure\Health;

class HealthStatus
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    public function __construct(
        public readonly string $status,
        public readonly float $responseTimeMs,
        public readonly array $details = [],
    ) {}

    public static function ok(float $responseTimeMs, array $details = []): self
    {
        return new self(self::OK, $responseTimeMs, $details);
    }

    public static function warning(float $responseTimeMs, array $details = []): self
    {
        return new self(self::WARNING, $responseTimeMs, $details);
    }

    public static function error(float $responseTimeMs, array $details = []): self
    {
        return new self(self::ERROR, $responseTimeMs, $details);
    }

    public function isHealthy(): bool
    {
        return $this->status !== self::ERROR;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'response_time_ms' => round($this->responseTimeMs, 2),
            'details' => $this->details,
        ];
    }
}
