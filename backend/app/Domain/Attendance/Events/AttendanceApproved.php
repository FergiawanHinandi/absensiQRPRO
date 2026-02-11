<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use App\Domain\Shared\DomainEvent;

final class AttendanceApproved extends DomainEvent
{
    public function __construct(
        public readonly int $attendanceId,
        public readonly int $studentId,
        public readonly int $scheduleId,
        int $schoolId,
        public readonly int $approverId,
    ) {
        parent::__construct(schoolId: $schoolId, actorId: $approverId);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'attendance_id' => $this->attendanceId,
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'approver_id' => $this->approverId,
        ]);
    }
}
