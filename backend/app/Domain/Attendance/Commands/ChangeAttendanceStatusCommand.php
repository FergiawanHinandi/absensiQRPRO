<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\Shared\Command;

/**
 * Change Attendance Status Command
 * 
 * Represents the intention to change an attendance record's status.
 * Used for manual corrections by teachers or administrators.
 * 
 * CQRS Pattern: This is a WRITE command
 */
class ChangeAttendanceStatusCommand implements Command
{
    public function __construct(
        public readonly int $attendanceId,
        public readonly string $newStatus,
        public readonly int $userId,
        public readonly ?string $reason = null,
    ) {
        $this->validate();
    }
    
    /**
     * Validate command data
     * 
     * @throws \InvalidArgumentException
     */
    public function validate(): void
    {
        // Validate attendance ID
        if ($this->attendanceId <= 0) {
            throw new \InvalidArgumentException('Attendance ID must be positive');
        }
        
        // Validate status
        $validStatuses = ['present', 'late', 'absent', 'excused', 'sick', 'permission'];
        if (!in_array($this->newStatus, $validStatuses)) {
            throw new \InvalidArgumentException(
                "Invalid status. Must be one of: " . implode(', ', $validStatuses)
            );
        }
        
        // Validate user ID
        if ($this->userId <= 0) {
            throw new \InvalidArgumentException('User ID must be positive');
        }
        
        // Validate reason length if provided
        if ($this->reason !== null && strlen($this->reason) > 500) {
            throw new \InvalidArgumentException('Reason must not exceed 500 characters');
        }
    }
    
    /**
     * Convert to array for event payload
     */
    public function toArray(): array
    {
        return [
            'attendance_id' => $this->attendanceId,
            'new_status' => $this->newStatus,
            'user_id' => $this->userId,
            'reason' => $this->reason,
        ];
    }
}
