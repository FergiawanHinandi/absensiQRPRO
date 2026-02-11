<?php

declare(strict_types=1);

namespace App\Domain\QR\ValueObjects;

use App\Domain\Shared\ValueObject;
use Carbon\CarbonImmutable;

final class QRToken extends ValueObject
{
    public function __construct(
        public readonly string $token,
        public readonly int $scheduleId,
        public readonly int $qrId,
        public readonly string $type,
        public readonly CarbonImmutable $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return CarbonImmutable::now()->isAfter($this->expiresAt);
    }

    public function remainingSeconds(): int
    {
        if ($this->isExpired()) {
            return 0;
        }

        return (int) CarbonImmutable::now()->diffInSeconds($this->expiresAt);
    }

    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'schedule_id' => $this->scheduleId,
            'qr_id' => $this->qrId,
            'type' => $this->type,
            'expires_at' => $this->expiresAt->toIso8601String(),
        ];
    }
}
