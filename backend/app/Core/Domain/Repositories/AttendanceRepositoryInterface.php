<?php

namespace App\Core\Domain\Repositories;

use App\Models\Attendance;

interface AttendanceRepositoryInterface
{
    /**
     * Check if student has already attended for a specific schedule and date.
     */
    public function hasAttended(int $studentId, int $scheduleId, string $date): bool;

    /**
     * Find attendance by request_id for idempotency.
     */
    public function findByRequestId(string $requestId): ?Attendance;

    /**
     * Create a new attendance record.
     */
    public function create(array $data): Attendance;

    /**
     * Find attendance by ID.
     */
    public function find(int $id): ?Attendance;
}
