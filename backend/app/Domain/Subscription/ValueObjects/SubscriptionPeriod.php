<?php

declare(strict_types=1);

namespace App\Domain\Subscription\ValueObjects;

use App\Domain\Shared\ValueObject;
use Carbon\CarbonImmutable;

final class SubscriptionPeriod extends ValueObject
{
    public function __construct(
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $expiresAt,
        public readonly int $gracePeriodDays = 3,
    ) {
        if ($expiresAt->isBefore($startsAt)) {
            throw new \InvalidArgumentException('Subscription expiry must be after start date.');
        }
    }

    public function isActive(): bool
    {
        $now = CarbonImmutable::now();

        return $now->between($this->startsAt, $this->expiresAt);
    }

    public function isExpired(): bool
    {
        return CarbonImmutable::now()->isAfter($this->expiresAt);
    }

    public function isInGracePeriod(): bool
    {
        $now = CarbonImmutable::now();
        $graceEnd = $this->expiresAt->addDays($this->gracePeriodDays);

        return $now->isAfter($this->expiresAt) && $now->isBefore($graceEnd);
    }

    public function daysRemaining(): int
    {
        $now = CarbonImmutable::now();

        if ($now->isAfter($this->expiresAt)) {
            return 0;
        }

        return (int) $now->diffInDays($this->expiresAt);
    }

    /**
     * Extend the subscription by a given number of days.
     */
    public function extend(int $days): self
    {
        return new self(
            startsAt: $this->startsAt,
            expiresAt: $this->expiresAt->addDays($days),
            gracePeriodDays: $this->gracePeriodDays,
        );
    }

    public function toArray(): array
    {
        return [
            'starts_at' => $this->startsAt->toIso8601String(),
            'expires_at' => $this->expiresAt->toIso8601String(),
            'grace_period_days' => $this->gracePeriodDays,
            'is_active' => $this->isActive(),
            'days_remaining' => $this->daysRemaining(),
        ];
    }
}
