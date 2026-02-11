<?php

declare(strict_types=1);

namespace App\Application\Services;

use App\Domain\Attendance\Commands\CheckInCommand;
use App\Domain\Attendance\Commands\CheckOutCommand;
use App\Domain\Attendance\Commands\RecordAttendanceCommand;
use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Commands\RequestCorrectionCommand;
use App\Domain\Attendance\Handlers\CheckInHandler;
use App\Domain\Attendance\Handlers\CheckOutHandler;
use App\Domain\Attendance\Handlers\RecordAttendanceHandler;
use App\Domain\Attendance\Handlers\ChangeAttendanceStatusHandler;
use App\Domain\Attendance\Handlers\RequestCorrectionHandler;
use App\Models\Attendance;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

/**
 * Attendance Application Service
 * 
 * This is the APPLICATION LAYER facade that orchestrates domain operations.
 * It provides a clean API for controllers and maintains backward compatibility
 * with existing code.
 * 
 * Responsibilities:
 * - Translate application requests to domain commands
 * - Coordinate command handlers
 * - Handle cross-cutting concerns (logging, caching, etc.)
 * - Provide backward-compatible methods
 * 
 * This service delegates to domain handlers (CQRS Write Model)
 * 
 * @package App\Application\Services
 */
class AttendanceApplicationService
{
    public function __construct(
        private CheckInHandler $checkInHandler,
        private CheckOutHandler $checkOutHandler,
        private RecordAttendanceHandler $recordAttendanceHandler,
        private ChangeAttendanceStatusHandler $changeStatusHandler,
        private RequestCorrectionHandler $requestCorrectionHandler,
    ) {}

    /**
     * Check in a student for attendance
     * 
     * @param array $data
     * @return Attendance
     */
    public function checkIn(array $data): Attendance
    {
        $command = new CheckInCommand(
            studentId: $data['student_id'],
            scheduleId: $data['schedule_id'],
            schoolId: $data['school_id'],
            attendanceDate: $data['attendance_date'],
            checkInTime: $data['check_in_time'] instanceof CarbonImmutable 
                ? $data['check_in_time'] 
                : CarbonImmutable::parse($data['check_in_time']),
            classId: $data['class_id'] ?? null,
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            recordedBy: $data['recorded_by'] ?? null,
            deviceId: $data['device_id'] ?? null,
            source: $data['source'] ?? 'student_scan',
            requestId: $data['request_id'] ?? null,
        );

        return $this->checkInHandler->handle($command);
    }

    /**
     * Check out a student from attendance
     * 
     * @param int $attendanceId
     * @param array $data
     * @return Attendance
     */
    public function checkOut(int $attendanceId, array $data): Attendance
    {
        $command = new CheckOutCommand(
            attendanceId: $attendanceId,
            checkOutTime: $data['check_out_time'] instanceof CarbonImmutable 
                ? $data['check_out_time'] 
                : CarbonImmutable::parse($data['check_out_time']),
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            recordedBy: $data['recorded_by'] ?? null,
            deviceId: $data['device_id'] ?? null,
        );

        return $this->checkOutHandler->handle($command);
    }

    /**
     * Record attendance (legacy method - backward compatible)
     * 
     * @param array $data
     * @return Attendance
     */
    public function recordAttendance(array $data): Attendance
    {
        $command = new RecordAttendanceCommand(
            studentId: $data['student_id'],
            scheduleId: $data['schedule_id'],
            schoolId: $data['school_id'],
            attendanceDate: $data['attendance_date'],
            status: $data['status'],
            checkInTime: isset($data['check_in_time']) 
                ? ($data['check_in_time'] instanceof CarbonImmutable 
                    ? $data['check_in_time'] 
                    : CarbonImmutable::parse($data['check_in_time']))
                : null,
            latitude: $data['latitude'] ?? null,
            longitude: $data['longitude'] ?? null,
            notes: $data['notes'] ?? null,
            recordedBy: $data['recorded_by'] ?? null,
            source: $data['source'] ?? 'student_scan',
        );

        return $this->recordAttendanceHandler->handle($command);
    }

    /**
     * Change attendance status
     * 
     * @param int $attendanceId
     * @param string $newStatus
     * @param int $changedBy
     * @param string|null $reason
     * @return Attendance
     */
    public function changeStatus(
        int $attendanceId, 
        string $newStatus, 
        int $changedBy, 
        ?string $reason = null
    ): Attendance {
        $command = new ChangeAttendanceStatusCommand(
            attendanceId: $attendanceId,
            newStatus: $newStatus,
            changedBy: $changedBy,
            reason: $reason,
        );

        return $this->changeStatusHandler->handle($command);
    }

    /**
     * Request a correction for an attendance record
     * 
     * @param int $attendanceId
     * @param string $reason
     * @param int $requesterId
     * @return Attendance
     */
    public function requestCorrection(
        int $attendanceId, 
        string $reason, 
        int $requesterId
    ): Attendance {
        $command = new RequestCorrectionCommand(
            attendanceId: $attendanceId,
            reason: $reason,
            requesterId: $requesterId,
        );

        return $this->requestCorrectionHandler->handle($command);
    }

    /**
     * Bulk check-in multiple students
     * 
     * @param array $students Array of student data
     * @return array Array of Attendance models
     */
    public function bulkCheckIn(array $students): array
    {
        $results = [];
        
        foreach ($students as $studentData) {
            try {
                $results[] = $this->checkIn($studentData);
            } catch (\Exception $e) {
                // Log error but continue processing
                \Log::error('Bulk check-in failed for student', [
                    'student_id' => $studentData['student_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }
}
