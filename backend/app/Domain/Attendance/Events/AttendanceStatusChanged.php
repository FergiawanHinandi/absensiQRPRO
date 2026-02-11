<?php

namespace App\Domain\Attendance\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Attendance Status Changed Event
 * 
 * Fired when an attendance record's status is manually changed.
 * This triggers read model updates and notifications.
 * 
 * CQRS Pattern: Domain event for write → read synchronization
 */
class AttendanceStatusChanged
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $attendanceId,
        public readonly int $schoolId,
        public readonly int $studentId,
        public readonly int $classId,
        public readonly string $attendanceDate,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        public readonly int $changedBy,
    ) {}
    
    /**
     * Get event payload for logging
     */
    public function toArray(): array
    {
        return [
            'attendance_id' => $this->attendanceId,
            'school_id' => $this->schoolId,
            'student_id' => $this->studentId,
            'class_id' => $this->classId,
            'attendance_date' => $this->attendanceDate,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'changed_by' => $this->changedBy,
        ];
    }
}
