<?php

declare(strict_types=1);

namespace App\Domain\Attendance\Rules;

class NoDuplicateAttendanceRule
{
    /**
     * Validate that no duplicate attendance exists for the same student/schedule/date.
     *
     * @param  int     $studentId
     * @param  int     $scheduleId
     * @param  string  $attendanceDate  Y-m-d format
     * @param  int|null $excludeId  Exclude this attendance ID (for updates)
     * @return bool true if no duplicate exists
     */
    public function validate(
        int $studentId,
        int $scheduleId,
        string $attendanceDate,
        ?int $excludeId = null,
    ): bool {
        $query = \App\Models\Attendance::query()
            ->where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->whereDate('attendance_date', $attendanceDate);

        if ($excludeId !== null) {
            $query->where('id', '!=', $excludeId);
        }

        return ! $query->exists();
    }

    /**
     * Validate and throw exception if duplicate exists.
     *
     * @throws \App\Exceptions\AttendanceException
     */
    public function enforce(
        int $studentId,
        int $scheduleId,
        string $attendanceDate,
        ?int $excludeId = null,
    ): void {
        if (! $this->validate($studentId, $scheduleId, $attendanceDate, $excludeId)) {
            throw new \App\Exceptions\AttendanceException(
                'Duplicate attendance record: student already has attendance for this schedule on this date.'
            );
        }
    }
}
