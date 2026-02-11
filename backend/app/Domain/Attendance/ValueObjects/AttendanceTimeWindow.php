<?php

declare(strict_types=1);

namespace App\Domain\Attendance\ValueObjects;

use App\Domain\Shared\ValueObject;
use Carbon\CarbonImmutable;

final class AttendanceTimeWindow extends ValueObject
{
    public function __construct(
        public readonly CarbonImmutable $scheduledStart,
        public readonly CarbonImmutable $scheduledEnd,
        public readonly int $preWindowMinutes = 10,
        public readonly int $postWindowMinutes = 5,
    ) {}

    /**
     * Get the earliest allowed check-in time.
     */
    public function windowOpens(): CarbonImmutable
    {
        return $this->scheduledStart->subMinutes($this->preWindowMinutes);
    }

    /**
     * Get the latest allowed check-in time.
     */
    public function windowCloses(): CarbonImmutable
    {
        return $this->scheduledEnd->addMinutes($this->postWindowMinutes);
    }

    /**
     * Check if a given time falls within the allowed window.
     */
    public function isWithinWindow(CarbonImmutable $now): bool
    {
        return $now->between($this->windowOpens(), $this->windowCloses());
    }

    /**
     * Check if a check-in time is considered late.
     */
    public function isLate(CarbonImmutable $checkInTime): bool
    {
        return $checkInTime->isAfter($this->scheduledStart);
    }

    /**
     * Calculate minutes late from scheduled start.
     */
    public function minutesLate(CarbonImmutable $checkInTime): int
    {
        if (! $this->isLate($checkInTime)) {
            return 0;
        }

        return (int) $checkInTime->diffInMinutes($this->scheduledStart);
    }

    /**
     * Get the total duration of the schedule in minutes.
     */
    public function durationMinutes(): int
    {
        return (int) $this->scheduledStart->diffInMinutes($this->scheduledEnd);
    }

    public function toArray(): array
    {
        return [
            'scheduled_start' => $this->scheduledStart->toIso8601String(),
            'scheduled_end' => $this->scheduledEnd->toIso8601String(),
            'pre_window_minutes' => $this->preWindowMinutes,
            'post_window_minutes' => $this->postWindowMinutes,
            'window_opens' => $this->windowOpens()->toIso8601String(),
            'window_closes' => $this->windowCloses()->toIso8601String(),
        ];
    }
}
