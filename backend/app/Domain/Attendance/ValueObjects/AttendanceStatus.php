<?php

declare(strict_types=1);

namespace App\Domain\Attendance\ValueObjects;

use App\Domain\Shared\ValueObject;

final class AttendanceStatus extends ValueObject
{
    public const PRESENT = 'present';

    public const LATE = 'late';

    public const ABSENT = 'absent';

    public const SICK = 'sick';

    public const EXCUSED = 'excused';

    public const PERMIT = 'permit';

    private const VALID_STATUSES = [
        self::PRESENT,
        self::LATE,
        self::ABSENT,
        self::SICK,
        self::EXCUSED,
        self::PERMIT,
    ];

    private const PRESENT_STATUSES = [
        self::PRESENT,
        self::LATE,
    ];

    public readonly string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower(trim($value));

        if (! in_array($normalized, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException(
                "Invalid attendance status [{$value}]. Valid: " . implode(', ', self::VALID_STATUSES)
            );
        }

        $this->value = $normalized;
    }

    public static function present(): self
    {
        return new self(self::PRESENT);
    }

    public static function late(): self
    {
        return new self(self::LATE);
    }

    public static function absent(): self
    {
        return new self(self::ABSENT);
    }

    public static function sick(): self
    {
        return new self(self::SICK);
    }

    public static function excused(): self
    {
        return new self(self::EXCUSED);
    }

    public function isPresent(): bool
    {
        return in_array($this->value, self::PRESENT_STATUSES, true);
    }

    public function isAbsent(): bool
    {
        return $this->value === self::ABSENT;
    }

    /**
     * Determine status from late minutes.
     */
    public static function fromLateMinutes(int $minutesLate, int $lateThreshold = 0): self
    {
        return $minutesLate > $lateThreshold ? self::late() : self::present();
    }

    public function toArray(): array
    {
        return ['value' => $this->value];
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
