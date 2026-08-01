<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Send Attendance Notification Job
 * 
 * Sends notifications to parents when student attendance is recorded.
 * 
 * TENANT SAFETY:
 * - Extends TenantAwareJob to ensure school_id context
 * - Validates attendance belongs to the correct school
 * - Prevents cross-tenant notification sending
 * 
 * USAGE:
 * SendAttendanceNotification::dispatch($attendance->school_id, $attendance->id);
 * 
 * @version 2.0.0 - Updated to extend TenantAwareJob for tenant safety
 */
class SendAttendanceNotification extends TenantAwareJob
{
    /**
     * The attendance ID to send notification for
     */
    protected int $attendanceId;

    /**
     * Create a new job instance.
     *
     * @param int $schoolId School ID for tenant context (REQUIRED for tenant safety)
     * @param int $attendanceId Attendance record ID
     */
    public function __construct(int $schoolId, int $attendanceId)
    {
        parent::__construct($schoolId);
        $this->attendanceId = $attendanceId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Load attendance with relationships
        $attendance = Attendance::with(['student.parents', 'school'])
            ->findOrFail($this->attendanceId);

        // ✅ TENANT SAFETY: Validate attendance belongs to this school
        if ($attendance->school_id !== $this->schoolId) {
            Log::error('Tenant context violation in SendAttendanceNotification', [
                'job_school_id' => $this->schoolId,
                'attendance_school_id' => $attendance->school_id,
                'attendance_id' => $this->attendanceId,
            ]);
            throw new \RuntimeException(
                "Tenant context violation: Attendance {$this->attendanceId} belongs to school {$attendance->school_id} " .
                "but job is for school {$this->schoolId}"
            );
        }

        $student = $attendance->student;
        
        // ✅ TENANT SAFETY: Validate student belongs to this school
        $this->ensureTenantContext($student);

        $parents = $student->parents;

        if ($parents->isEmpty()) {
            Log::info("No parents found for student: {$student->name} (ID: {$student->id})");
            return;
        }

        foreach ($parents as $parent) {
            // ✅ TENANT SAFETY: Validate parent belongs to this school
            $this->ensureTenantContext($parent);
            $this->sendNotification($parent, $student, $attendance);
        }
    }

    protected function sendNotification(User $parent, User $student, Attendance $attendance)
    {
        // In a real app, you would use a notification channel (FCM, WhatsApp, Email)
        // For now, we simulate by logging or hypothetical API call

        $message = "Halo {$parent->name}, anak Anda {$student->name} telah melakukan absensi pada {$attendance->check_in_time->format('H:i')}. Status: {$attendance->status}.";

        // Example: Log notification (Simulation)
        Log::channel('attendance')->info("NOTIFICATION_SENT: To {$parent->email} - {$message}");

        // Future Integration:
        // Http::post('https://wa-gateway.com/send', ['phone' => $parent->phone, 'message' => $message]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendAttendanceNotification job failed', [
            'job' => self::class,
            'school_id' => $this->schoolId,
            'attendance_id' => $this->attendanceId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return array_merge(parent::tags(), [
            'notification',
            'attendance',
            "attendance:{$this->attendanceId}",
        ]);
    }
}
