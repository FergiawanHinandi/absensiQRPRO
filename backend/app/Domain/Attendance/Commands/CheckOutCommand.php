<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Commands;

use App\Domain\Shared\Command;
use Carbon\CarbonImmutable;

/**
 * Check-Out Command
 * 
 * Represents the intent to check out a student from attendance.
 * This is part of the WRITE MODEL in CQRS architecture.
 * 
 * @package App\Domain\Attendance\Commands
 */
readonly class CheckOutCommand implements Command
{
    public function __construct(
        public int $attendanceId,
        public CarbonImmutable $checkOutTime,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $recordedBy = null,
        public ?string $deviceId = null,
    ) {}

    public function getName(): string
    {
        return 'CheckOutCommand';
    }

    /**
     * Validate the command data
     *
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->attendanceId <= 0) {
            throw new \InvalidArgumentException('Invalid attendance ID');
        }
    }

    public function getPayload(): array
    {
        return [
            'attendance_id' => $this->attendanceId,
            'check_out_time' => $this->checkOutTime->toIso8601String(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'recorded_by' => $this->recordedBy,
            'device_id' => $this->deviceId,
        ];
    }
}
