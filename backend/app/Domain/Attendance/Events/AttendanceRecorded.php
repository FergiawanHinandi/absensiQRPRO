<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use App\Domain\Shared\DomainEvent;

/**
 * Attendance Recorded Event
 *
 * Dispatched when a new attendance record is successfully created.
 * This event triggers the update of read models (daily summaries).
 *
 * CQRS Pattern: This bridges the Write Model and Read Model
 */
final class AttendanceRecorded extends DomainEvent
{
    public function __construct(
        public readonly int $attendanceId,
        public readonly int $studentId,
        public readonly int $scheduleId,
        int $schoolId,
        public readonly string $status,
        public readonly string $checkInTime,
        public readonly ?float $lat,
        public readonly ?float $lng,
        public readonly string $recordedBy,
        public readonly ?int $classId = null,
        public readonly string $attendanceDate = '',
        public readonly string $previousStatus = 'absent',
        public readonly bool $isUpdate = false,
        ?int $actorId = null,
    ) {
        parent::__construct(schoolId: $schoolId, actorId: $actorId);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'attendance_id' => $this->attendanceId,
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'status' => $this->status,
            'check_in_time' => $this->checkInTime,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'recorded_by' => $this->recordedBy,
            'class_id' => $this->classId,
            'attendance_date' => $this->attendanceDate,
            'previous_status' => $this->previousStatus,
            'is_update' => $this->isUpdate,
        ]);
    }
}
