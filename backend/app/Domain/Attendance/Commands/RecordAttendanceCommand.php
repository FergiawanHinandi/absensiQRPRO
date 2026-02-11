<?php

namespace App\Domain\Attendance\Commands;

use App\Domain\Shared\Command;
use Carbon\Carbon;

/**
 * Record Attendance Command
 * 
 * Represents the intention to record a student's attendance.
 * This is the WRITE side of CQRS.
 */
class RecordAttendanceCommand implements Command
{
    public function __construct(
        public readonly int $schoolId,
        public readonly int $studentId,
        public readonly int $scheduleId,
        public readonly int $classId,
        public readonly Carbon $attendanceDate,
        public readonly string $status, // 'present', 'late', 'absent', 'excused'
        public readonly ?Carbon $checkInTime = null,
        public readonly ?float $latitude = null,
        public readonly ?float $longitude = null,
        public readonly ?string $deviceId = null,
        public readonly ?int $qrCodeId = null,
        public readonly bool $isManual = false,
        public readonly ?int $recordedBy = null,
        public readonly ?string $notes = null,
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
        if ($this->schoolId <= 0) {
            throw new \InvalidArgumentException('Invalid school ID');
        }
        
        if ($this->studentId <= 0) {
            throw new \InvalidArgumentException('Invalid student ID');
        }
        
        if ($this->scheduleId <= 0) {
            throw new \InvalidArgumentException('Invalid schedule ID');
        }
        
        if ($this->classId <= 0) {
            throw new \InvalidArgumentException('Invalid class ID');
        }
        
        $validStatuses = ['present', 'late', 'absent', 'excused'];
        if (!in_array($this->status, $validStatuses)) {
            throw new \InvalidArgumentException(
                "Invalid status. Must be one of: " . implode(', ', $validStatuses)
            );
        }
        
        // If status is present or late, check-in time is required
        if (in_array($this->status, ['present', 'late']) && !$this->checkInTime) {
            throw new \InvalidArgumentException(
                'Check-in time is required for present/late status'
            );
        }
        
        // If not manual, QR code ID is required
        if (!$this->isManual && !$this->qrCodeId) {
            throw new \InvalidArgumentException(
                'QR code ID is required for non-manual attendance'
            );
        }
        
        // If manual, recorded_by is required
        if ($this->isManual && !$this->recordedBy) {
            throw new \InvalidArgumentException(
                'Recorded by user ID is required for manual attendance'
            );
        }
    }
    
    /**
     * Convert to array for event payload
     */
    public function toArray(): array
    {
        return [
            'school_id' => $this->schoolId,
            'student_id' => $this->studentId,
            'schedule_id' => $this->scheduleId,
            'class_id' => $this->classId,
            'attendance_date' => $this->attendanceDate->toDateString(),
            'status' => $this->status,
            'check_in_time' => $this->checkInTime?->toDateTimeString(),
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'device_id' => $this->deviceId,
            'qr_code_id' => $this->qrCodeId,
            'is_manual' => $this->isManual,
            'recorded_by' => $this->recordedBy,
            'notes' => $this->notes,
        ];
    }
}
