<?php

namespace App\Events\Attendance;

use App\Events\DomainEvent;

class AttendanceRecorded extends DomainEvent
{
    protected function getEventType(): string
    {
        return 'attendance.recorded.v1';
    }

    protected function getAggregateType(): string
    {
        return 'Attendance';
    }

    /**
     * Create from attendance model
     */
    public static function fromAttendance($attendance): self
    {
        return new self([
            'aggregate_id' => $attendance->id,
            'school_id' => $attendance->school_id,
            'student_id' => $attendance->student_id,
            'class_id' => $attendance->class_id,
            'status' => $attendance->status,
            'check_in_time' => $attendance->check_in_time?->toIso8601String(),
            'check_out_time' => $attendance->check_out_time?->toIso8601String(),
            'location' => [
                'latitude' => $attendance->latitude,
                'longitude' => $attendance->longitude,
            ],
            'qr_nonce' => $attendance->qr_nonce,
            'device_info' => [
                'device_id' => $attendance->device_id,
                'platform' => $attendance->platform,
            ],
        ]);
    }
}
