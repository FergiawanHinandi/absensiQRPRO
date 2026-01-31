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
        return Attendance::create($data);
    }

    public function find(int $id): ?Attendance
    {
        return Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
            ->find($id);
    }
}
