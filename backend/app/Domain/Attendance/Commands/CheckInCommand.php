<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Commands;

use App\Domain\Shared\Command;
use Carbon\CarbonImmutable;

/**
 * Check-In Command
 * 
 * Represents the intent to check in a student for attendance.
 * This is part of the WRITE MODEL in CQRS architecture.
 * 
 * Commands are:
 * - Immutable (readonly properties)
 * - Represent user intent
 * - Validated before handling
 * - Do not contain business logic
 * 
 * @package App\Domain\Attendance\Commands
 */
readonly class CheckInCommand implements Command
{
    public function __construct(
        public int $studentId,
        public int $scheduleId,
        public int $schoolId,
        public string $attendanceDate,
        public CarbonImmutable $checkInTime,
        public ?int $classId = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $recordedBy = null,
        public ?string $deviceId = null,
        public ?string $source = 'student_scan',
        public ?string $requestId = null,
    ) {}

    /**
     * Get command name for logging/debugging
     */
    public function getName(): string
    {
        return 'CheckInCommand';
    }

    /**
     * Validate the command data
     *
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        if ($this->studentId <= 0) {
            throw new \InvalidArgumentException('Invalid student ID');
        }

        if ($this->scheduleId <= 0) {
            throw new \InvalidArgumentException('Invalid schedule ID');
        }

        if ($this->schoolId <= 0) {
            throw new \InvalidArgumentException('Invalid school ID');
        }

        if (! \Illuminate\Support\Carbon::parse($this->attendanceDate)->isToday()) {
            throw new \InvalidArgumentException('Attendance date must be today');
        }
    }

    /**
     * Get command payload for auditing
     */
    public function getPayload(): array
    {
        return [
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'school_id' => $this->schoolId,
            'attendance_date' => $this->attendanceDate,
            'check_in_time' => $this->checkInTime->toIso8601String(),
            'class_id' => $this->classId,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'recorded_by' => $this->recordedBy,
            'device_id' => $this->deviceId,
            'source' => $this->source,
            'request_id' => $this->requestId,
        ];
    }
}
