<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Events;

use App\Domain\Shared\DomainEvent;

final class CorrectionRequested extends DomainEvent
{
    public function __construct(
        public readonly int $attendanceId,
        public readonly int $studentId,
        public readonly int $scheduleId,
        int $schoolId,
        public readonly string $reason,
        public readonly int $requesterId,
    ) {
        parent::__construct(schoolId: $schoolId, actorId: $requesterId);
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'attendance_id' => $this->attendanceId,
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'reason' => $this->reason,
            'requester_id' => $this->requesterId,
        ]);
    }
}
