<?php

declare(strict_types=1);

namespace App\Application\Projectors;

use App\Domain\Attendance\Events\AttendanceApproved;
use App\Domain\Attendance\Events\AttendanceCheckedOut;
use App\Domain\Attendance\Events\AttendanceRecorded;
use App\Infrastructure\Persistence\AttendanceSummaryReadModel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Projects attendance domain events into the read-model summary table.
 *
 * Runs asynchronously on the 'projections' queue.
 */
class AttendanceSummaryProjector implements ShouldQueue
{
    public string $queue = 'projections';

    public int $tries = 3;

    public int $backoff = 5;

    public function __construct(
        private readonly AttendanceSummaryReadModel $readModel,
    ) {}

    /**
     * Handle a new attendance recording.
     */
    public function onAttendanceRecorded(AttendanceRecorded $event): void
    {
        try {
            $this->readModel->recordAttendance(
                schoolId: $event->schoolId,
                classId: $event->classId,
                scheduleId: $event->scheduleId,
                attendanceDate: $event->attendanceDate ?: now()->format('Y-m-d'),
                status: $event->status,
            );

            Log::channel('attendance_json')->debug('Summary projected: AttendanceRecorded', [
                'attendance_id' => $event->attendanceId,
                'school_id' => $event->schoolId,
                'status' => $event->status,
            ]);
        } catch (\Throwable $e) {
            Log::channel('attendance_json')->error('Summary projection failed: AttendanceRecorded', [
                'attendance_id' => $event->attendanceId,
                'error' => $e->getMessage(),
            ]);

            throw $e; // Re-throw for queue retry
        }
    }

    /**
     * Handle attendance updates (status changes or corrections).
     */
    public function onAttendanceApproved(AttendanceApproved $event): void
    {
        try {
            // Approval changes status from pending → present
            $this->readModel->updateStatus(
                schoolId: $event->schoolId,
                classId: null,
                scheduleId: $event->scheduleId,
                attendanceDate: now()->format('Y-m-d'),
                oldStatus: 'absent',
                newStatus: 'present',
            );
        } catch (\Throwable $e) {
            Log::channel('attendance_json')->error('Summary projection failed: AttendanceApproved', [
                'attendance_id' => $event->attendanceId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Subscribe to events.
     *
     * @return array<string, string>
     */
    public function subscribe($events): array
    {
        return [
            AttendanceRecorded::class => 'onAttendanceRecorded',
            AttendanceApproved::class => 'onAttendanceApproved',
        ];
    }
}
