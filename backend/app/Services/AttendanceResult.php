<?php

namespace App\Services;

use App\Models\Attendance;

/**
 * AttendanceResult Data Transfer Object
 *
 * Encapsulates the result of an attendance operation.
 * Used to return structured data from service to controller.
 *
 * IDEMPOTENCY SUPPORT:
 * The isIdempotentRetry flag indicates whether this result is from
 * a retry request that found an existing attendance record.
 */
final class AttendanceResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?Attendance $attendance = null,
        public readonly string $message = '',
        public readonly string $status = '',
        public readonly array $errors = [],
        public readonly bool $isIdempotentRetry = false,
    ) {}

    /**
     * Convert to array for JSON response
     */
    public function toArray(): array
    {
        $data = [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->success ? $this->getAttendanceData() : [],
            'errors' => $this->errors,
        ];

        // Add idempotency flag for clients to detect retries
        if ($this->isIdempotentRetry) {
            $data['idempotent_retry'] = true;
        }

        return $data;
    }

    /**
     * Get formatted attendance data
     */
    private function getAttendanceData(): array
    {
        if (! $this->attendance) {
            return [];
        }

        return [
            'attendance' => [
                'id' => $this->attendance->id,
                'status' => $this->attendance->status,
                'check_in_time' => $this->attendance->check_in_time?->format('H:i:s'),
                'attendance_date' => $this->attendance->attendance_date,
                'schedule_id' => $this->attendance->schedule_id,
                'student_id' => $this->attendance->student_id,
                'request_id' => $this->attendance->request_id,
            ],
        ];
    }

    /**
     * Create a success result
     */
    public static function success(
        Attendance $attendance,
        string $status = 'present',
        bool $isRetry = false
    ): self {
        return new self(
            success: true,
            attendance: $attendance,
            message: $isRetry ? 'Absensi sudah tercatat sebelumnya.' : 'Absensi berhasil dicatat.',
            status: $status,
            isIdempotentRetry: $isRetry,
        );
    }

    /**
     * Create a failure result
     */
    public static function failure(string $message, array $errors = []): self
    {
        return new self(
            success: false,
            message: $message,
            errors: $errors,
        );
    }

    /**
     * Check if result indicates success
     */
    public function isSuccessful(): bool
    {
        return $this->success;
    }

    /**
     * Check if this is an idempotent retry
     */
    public function isRetry(): bool
    {
        return $this->isIdempotentRetry;
    }

    /**
     * Get HTTP status code
     *
     * Returns:
     * - 201 Created: For new attendance records
     * - 200 OK: For idempotent retries (record already existed)
     * - 400 Bad Request: For failures
     */
    public function getHttpStatusCode(): int
    {
        if (! $this->success) {
            return 400;
        }

        // 200 for idempotent retry, 201 for new record
        return $this->isIdempotentRetry ? 200 : 201;
    }
}
