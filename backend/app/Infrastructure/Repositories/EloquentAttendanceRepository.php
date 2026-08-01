<?php

namespace App\Infrastructure\Repositories;

use App\Core\Domain\Repositories\AttendanceRepositoryInterface;
use App\Models\Attendance;

class EloquentAttendanceRepository implements AttendanceRepositoryInterface
{
    public function hasAttended(int $studentId, int $scheduleId, string $date): bool
    {
        return Attendance::where('student_id', $studentId)
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', $date)
            ->exists();
    }

    public function findByRequestId(string $requestId): ?Attendance
    {
        return Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
            ->where('request_id', $requestId)
            ->first();
    }

    public function create(array $data): Attendance
    {
        // Use firstOrCreate to prevent duplicates
        // Extract unique keys for the constraint
        $uniqueKeys = [
            'student_id' => $data['student_id'],
            'schedule_id' => $data['schedule_id'],
            'attendance_date' => $data['attendance_date'],
            'school_id' => $data['school_id'],
        ];

        // Remove unique keys from data to avoid duplication
        $attributes = array_diff_key($data, $uniqueKeys);

        $attendance = Attendance::firstOrCreate($uniqueKeys, $attributes);

        // Check if attendance already existed (constraint violation handling)
        if (!$attendance->wasRecentlyCreated) {
            throw new \App\Exceptions\AttendanceException(
                'Attendance record already exists for this student, schedule, and date.'
            );
        }

        return $attendance;
    }

    public function find(int $id): ?Attendance
    {
        return Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
            ->find($id);
    }
}
