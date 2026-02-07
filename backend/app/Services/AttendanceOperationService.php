<?php

namespace App\Services;

use App\Events\AttendanceLate;
use App\Events\AttendanceRecorded;
use App\Events\AttendanceUpdated;
use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AttendanceOperationService
 *
 * Service layer untuk operasi update, approval, dan koreksi absensi.
 *
 * RESPONSIBILITIES:
 * - Update status absensi
 * - Approve/Reject corrections
 * - Generate attendance dari permission
 * - Maintain audit trail
 *
 * RULES:
 * - Semua operasi dibungkus DB::transaction()
 * - Tidak ada perubahan status langsung via model
 * - Semua perubahan di-log ke AttendanceLog
 */
class AttendanceOperationService
{
    /**
     * Maximum days allowed for attendance update
     */
    private const MAX_UPDATE_DAYS = 7;

    /**
     * Update attendance status
     *
     * @param int $attendanceId
     * @param array $data ['status', 'notes', 'reason']
     * @param User $updatedBy
     * @return Attendance
     * @throws AttendanceException
     */
    public function update(int $attendanceId, array $data, User $updatedBy): Attendance
    {
        return DB::transaction(function () use ($attendanceId, $data, $updatedBy) {
            // 1. Lock row for update
            $attendance = Attendance::lockForUpdate()->findOrFail($attendanceId);

            // 2. Validate business rules
            $this->validateUpdateRules($attendance, $updatedBy);

            // 3. Store old values for audit
            $oldStatus = $attendance->status;
            $oldNotes = $attendance->notes;

            // 4. Update via model (triggers observers)
            $attendance->status = $data['status'];
            $attendance->notes = $data['notes'] ?? $attendance->notes;
            $attendance->updated_by = $updatedBy->id;
            $attendance->save();

            // 5. Create audit log
            $this->createAuditLog($attendance, 'update', [
                'old_status' => $oldStatus,
                'new_status' => $data['status'],
                'old_notes' => $oldNotes,
                'new_notes' => $data['notes'] ?? null,
                'reason' => $data['reason'] ?? null,
            ], $updatedBy);

            // 6. Dispatch event
            if (class_exists(AttendanceUpdated::class)) {
                event(new AttendanceUpdated($attendance, $oldStatus, $data['status']));
            }

            Log::channel('attendance')->info('Attendance updated', [
                'attendance_id' => $attendance->id,
                'old_status' => $oldStatus,
                'new_status' => $data['status'],
                'updated_by' => $updatedBy->id,
            ]);

            return $attendance->fresh();
        });
    }

    /**
     * Submit correction request
     *
     * @param int $attendanceId
     * @param array $data ['requested_status', 'reason', 'attachment']
     * @param User $requester
     * @return Attendance
     */
    public function submitCorrection(int $attendanceId, array $data, User $requester): Attendance
    {
        return DB::transaction(function () use ($attendanceId, $data, $requester) {
            $attendance = Attendance::lockForUpdate()->findOrFail($attendanceId);

            // Validate correction can be requested
            $this->validateCorrectionRequest($attendance, $requester);

            // Update with pending correction
            $attendance->correction_status = 'pending';
            $attendance->correction_requested_at = now();
            $attendance->correction_requested_by = $requester->id;
            $attendance->correction_requested_status = $data['requested_status'];
            $attendance->correction_reason = $data['reason'];
            $attendance->correction_attachment = $data['attachment'] ?? null;
            $attendance->save();

            // Create audit log
            $this->createAuditLog($attendance, 'correction_requested', [
                'current_status' => $attendance->status,
                'requested_status' => $data['requested_status'],
                'reason' => $data['reason'],
            ], $requester);

            Log::channel('attendance')->info('Correction requested', [
                'attendance_id' => $attendance->id,
                'requested_by' => $requester->id,
                'requested_status' => $data['requested_status'],
            ]);

            return $attendance;
        });
    }

    /**
     * Approve correction request
     *
     * @param int $attendanceId
     * @param User $approver
     * @param string|null $notes
     * @return Attendance
     */
    public function approveCorrection(int $attendanceId, User $approver, ?string $notes = null): Attendance
    {
        return DB::transaction(function () use ($attendanceId, $approver, $notes) {
            $attendance = Attendance::lockForUpdate()->findOrFail($attendanceId);

            // Validate approval
            $this->validateApproval($attendance, $approver);

            $oldStatus = $attendance->status;
            $newStatus = $attendance->correction_requested_status;

            // Apply correction
            $attendance->status = $newStatus;
            $attendance->correction_status = 'approved';
            $attendance->correction_approved_at = now();
            $attendance->correction_approved_by = $approver->id;
            $attendance->correction_approval_notes = $notes;
            $attendance->save();

            // Create audit log
            $this->createAuditLog($attendance, 'correction_approved', [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'notes' => $notes,
            ], $approver);

            Log::channel('attendance')->info('Correction approved', [
                'attendance_id' => $attendance->id,
                'approved_by' => $approver->id,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            return $attendance->fresh();
        });
    }

    /**
     * Reject correction request
     *
     * @param int $attendanceId
     * @param User $rejecter
     * @param string $reason
     * @return Attendance
     */
    public function rejectCorrection(int $attendanceId, User $rejecter, string $reason): Attendance
    {
        return DB::transaction(function () use ($attendanceId, $rejecter, $reason) {
            $attendance = Attendance::lockForUpdate()->findOrFail($attendanceId);

            // Validate rejection
            $this->validateApproval($attendance, $rejecter);

            // Mark as rejected
            $attendance->correction_status = 'rejected';
            $attendance->correction_approved_at = now();
            $attendance->correction_approved_by = $rejecter->id;
            $attendance->correction_approval_notes = $reason;
            $attendance->save();

            // Create audit log
            $this->createAuditLog($attendance, 'correction_rejected', [
                'reason' => $reason,
            ], $rejecter);

            Log::channel('attendance')->info('Correction rejected', [
                'attendance_id' => $attendance->id,
                'rejected_by' => $rejecter->id,
                'reason' => $reason,
            ]);

            return $attendance;
        });
    }

    /**
     * Create attendance records from permission (izin sakit/ijin)
     *
     * @param object $permission Permission record
     * @param User $approvedBy
     * @return Collection
     */
    public function createFromPermission(object $permission, User $approvedBy): Collection
    {
        return DB::transaction(function () use ($permission, $approvedBy) {
            $attendances = collect();
            $start = Carbon::parse($permission->start_date);
            $end = Carbon::parse($permission->end_date);

            // Map permission type to attendance status
            $status = match ($permission->type) {
                'sick' => 'sick',
                'permit', 'izin' => 'permit',
                default => 'excused',
            };

            while ($start->lte($end)) {
                // Skip weekends if configured
                if (!$this->isSchoolDay($start, $permission->school_id)) {
                    $start->addDay();
                    continue;
                }

                // Find or create attendance record
                $attendance = Attendance::updateOrCreate(
                    [
                        'school_id' => $permission->school_id,
                        'student_id' => $permission->student_id,
                        'attendance_date' => $start->toDateString(),
                    ],
                    [
                        'class_id' => $permission->class_id,
                        'status' => $status,
                        'is_manual' => true,
                        'attendance_type' => 'permission',
                        'notes' => "Izin Digital: {$permission->reason}",
                        'recorded_by' => $approvedBy->id,
                        'permission_id' => $permission->id,
                    ]
                );

                // Create audit log
                $this->createAuditLog($attendance, 'created_from_permission', [
                    'permission_id' => $permission->id,
                    'permission_type' => $permission->type,
                    'status' => $status,
                ], $approvedBy);

                $attendances->push($attendance);
                $start->addDay();
            }

            Log::channel('attendance')->info('Attendance created from permission', [
                'permission_id' => $permission->id,
                'student_id' => $permission->student_id,
                'count' => $attendances->count(),
                'created_by' => $approvedBy->id,
            ]);

            return $attendances;
        });
    }

    /**
     * Delete attendance (soft delete)
     *
     * @param int $attendanceId
     * @param User $deletedBy
     * @param string|null $reason
     * @return bool
     */
    public function delete(int $attendanceId, User $deletedBy, ?string $reason = null): bool
    {
        return DB::transaction(function () use ($attendanceId, $deletedBy, $reason) {
            $attendance = Attendance::lockForUpdate()->findOrFail($attendanceId);

            // Create audit log before deletion
            $this->createAuditLog($attendance, 'deleted', [
                'status' => $attendance->status,
                'reason' => $reason,
            ], $deletedBy);

            // Soft delete
            $attendance->deleted_by = $deletedBy->id;
            $attendance->deletion_reason = $reason;
            $attendance->save();
            $attendance->delete();

            Log::channel('attendance')->warning('Attendance deleted', [
                'attendance_id' => $attendance->id,
                'deleted_by' => $deletedBy->id,
                'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Validate update rules
     */
    private function validateUpdateRules(Attendance $attendance, User $updatedBy): void
    {
        // Rule 1: Cannot update attendance older than MAX_UPDATE_DAYS
        $daysSinceAttendance = now()->diffInDays($attendance->attendance_date);
        if ($daysSinceAttendance > self::MAX_UPDATE_DAYS) {
            throw new AttendanceException(
                "Tidak dapat mengubah absensi lebih dari " . self::MAX_UPDATE_DAYS . " hari yang lalu."
            );
        }

        // Rule 2: School isolation
        if ($attendance->school_id !== $updatedBy->school_id && $updatedBy->role_type !== 'super_admin') {
            throw new AttendanceException("Anda tidak memiliki akses ke data ini.");
        }
    }

    /**
     * Validate correction request
     */
    private function validateCorrectionRequest(Attendance $attendance, User $requester): void
    {
        // Cannot request correction if one is pending
        if ($attendance->correction_status === 'pending') {
            throw new AttendanceException("Permintaan koreksi sebelumnya masih menunggu persetujuan.");
        }

        // Check if within allowed time frame
        $daysSinceAttendance = now()->diffInDays($attendance->attendance_date);
        if ($daysSinceAttendance > self::MAX_UPDATE_DAYS) {
            throw new AttendanceException(
                "Tidak dapat mengajukan koreksi untuk absensi lebih dari " . self::MAX_UPDATE_DAYS . " hari yang lalu."
            );
        }
    }

    /**
     * Validate approval
     */
    private function validateApproval(Attendance $attendance, User $approver): void
    {
        // Must have pending correction
        if ($attendance->correction_status !== 'pending') {
            throw new AttendanceException("Tidak ada permintaan koreksi yang menunggu persetujuan.");
        }

        // School isolation
        if ($attendance->school_id !== $approver->school_id && $approver->role_type !== 'super_admin') {
            throw new AttendanceException("Anda tidak memiliki akses ke data ini.");
        }
    }

    /**
     * Create audit log entry
     */
    private function createAuditLog(Attendance $attendance, string $action, array $changes, User $actor): void
    {
        // Try to create AttendanceLog if table exists
        try {
            if (class_exists(AttendanceLog::class)) {
                AttendanceLog::create([
                    'attendance_id' => $attendance->id,
                    'action' => $action,
                    'changes' => json_encode($changes),
                    'performed_by' => $actor->id,
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'created_at' => now(),
                ]);
            }
        } catch (\Exception $e) {
            // Log to file if table doesn't exist
            Log::channel('attendance')->info("Audit: {$action}", [
                'attendance_id' => $attendance->id,
                'changes' => $changes,
                'performed_by' => $actor->id,
            ]);
        }
    }

    /**
     * Check if date is a school day
     */
    private function isSchoolDay(Carbon $date, int $schoolId): bool
    {
        // Skip weekends
        if ($date->isWeekend()) {
            return false;
        }

        // TODO: Check school calendar for holidays
        return true;
    }
}
