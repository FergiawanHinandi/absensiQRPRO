<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Rules;

use App\Domain\Attendance\ValueObjects\AttendanceTimeWindow;
use Carbon\CarbonImmutable;

class TimeWindowRule
{
    /**
     * Validate that the given time falls within the attendance time window.
     */
    public function validate(CarbonImmutable $time, AttendanceTimeWindow $window): bool
    {
        return $window->isWithinWindow($time);
    }

    /**
     * Validate and throw exception if outside window.
     *
     * @throws \App\Exceptions\AttendanceException
     */
    public function enforce(CarbonImmutable $time, AttendanceTimeWindow $window): void
    {
        if (! $this->validate($time, $window)) {
            throw new \App\Exceptions\AttendanceException(
                'Attendance time is outside the allowed window. ' .
                "Allowed: {$window->windowOpens()->format('H:i')} - {$window->windowCloses()->format('H:i')}. " .
                "Attempted: {$time->format('H:i')}."
            );
        }
    }
}
