<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Exception thrown when attempting to create duplicate attendance record
 * 
 * This exception is thrown when the unique constraint on
 * (student_id, schedule_id, attendance_date, school_id) is violated.
 */
class DuplicateAttendanceException extends Exception
{
    protected $studentId;
    protected $scheduleId;
    protected $attendanceDate;
    protected $schoolId;
    protected $existingAttendanceId;

    public function __construct(
        int $studentId,
        int $scheduleId,
        string $attendanceDate,
        int $schoolId,
        ?int $existingAttendanceId = null,
        string $message = null
    ) {
        $this->studentId = $studentId;
        $this->scheduleId = $scheduleId;
        $this->attendanceDate = $attendanceDate;
        $this->schoolId = $schoolId;
        $this->existingAttendanceId = $existingAttendanceId;

        $defaultMessage = $message ?? 'Attendance record already exists for this student, schedule, and date.';
        
        parent::__construct($defaultMessage, 409);
    }

    /**
     * Get user-friendly error message in Indonesian
     */
    public function getUserMessage(): string
    {
        return 'Anda sudah melakukan absensi untuk jadwal ini pada tanggal yang sama. ' .
               'Tidak dapat membuat absensi duplikat.';
    }

    /**
     * Render the exception as an HTTP response
     */
    public function render(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => 'DUPLICATE_ATTENDANCE',
            'message' => $this->getUserMessage(),
            'details' => [
                'student_id' => $this->studentId,
                'schedule_id' => $this->scheduleId,
                'attendance_date' => $this->attendanceDate,
                'school_id' => $this->schoolId,
                'existing_attendance_id' => $this->existingAttendanceId,
            ],
        ], 409);
    }

    /**
     * Get the student ID
     */
    public function getStudentId(): int
    {
        return $this->studentId;
    }

    /**
     * Get the schedule ID
     */
    public function getScheduleId(): int
    {
        return $this->scheduleId;
    }

    /**
     * Get the attendance date
     */
    public function getAttendanceDate(): string
    {
        return $this->attendanceDate;
    }

    /**
     * Get the school ID
     */
    public function getSchoolId(): int
    {
        return $this->schoolId;
    }

    /**
     * Get the existing attendance ID
     */
    public function getExistingAttendanceId(): ?int
    {
        return $this->existingAttendanceId;
    }
}
