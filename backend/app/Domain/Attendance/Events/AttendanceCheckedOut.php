<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use App\Domain\Shared\DomainEvent;

final class AttendanceCheckedOut extends DomainEvent
{
    public function __construct(
        public readonly int $attendanceId,
        public readonly int $studentId,
        public readonly int $scheduleId,
        int $schoolId,
        public readonly string $checkOutTime,
        public readonly ?float $lat,
        public readonly ?float $lng,
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
            'check_out_time' => $this->checkOutTime,
            'lat' => $this->lat,
            'lng' => $this->lng,
        ]);
    }
}
