<?php

namespace App\Domain\Attendance\Handlers;

use App\Domain\Attendance\Commands\ChangeAttendanceStatusCommand;
use App\Domain\Attendance\Events\AttendanceStatusChanged;
use App\Domain\Shared\Command;
use App\Domain\Shared\CommandHandler;
use App\Models\Attendance;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Change Attendance Status Handler
 * 
 * Handles status changes with:
 * - State machine validation
 * - Database transaction
 * - Audit logging
 * - Domain event dispatch
 * 
 * CQRS Pattern: This is the WRITE side handler
 */
class ChangeAttendanceStatusHandler implements CommandHandler
{
    /**
     * Handle the command
     * 
     * @param ChangeAttendanceStatusCommand $command
     * @return Attendance
     * @throws \Exception
     */
    public function handle(Command $command): Attendance
    {
        if (!$command instanceof ChangeAttendanceStatusCommand) {
            throw new \InvalidArgumentException('Invalid command type');
        }
        
        return DB::transaction(function () use ($command) {
            // 1. Retrieve attendance with school scope
            $attendance = $this->getAttendance($command->attendanceId);
            
            // 2. Store old status for audit
            $oldStatus = $attendance->status;
            
            // 3. Validate state transition using state machine
            $this->validateStateTransition($attendance, $command->newStatus);
            
            // 4. Update status
            $attendance = $this->updateStatus($attendance, $command->newStatus);
            
            // 5. Create audit log
            $this->createAuditLog(
                attendance: $attendance,
                userId: $command->userId,
                oldStatus: $oldStatus,
                newStatus: $command->newStatus,
                reason: $command->reason
            );
            
            // 6. Dispatch domain event (for read model update)
            // Use afterCommit() to ensure event is only dispatched after transaction commits
            event(new AttendanceStatusChanged(
                attendanceId: $attendance->id,
                schoolId: $attendance->school_id,
                studentId: $attendance->student_id,
                classId: $attendance->class_id,
                attendanceDate: $attendance->attendance_date->toDateString(),
                oldStatus: $oldStatus,
                newStatus: $command->newStatus,
                changedBy: $command->userId,
            ))->afterCommit();
            
            Log::info('Attendance status changed', [
                'attendance_id' => $attendance->id,
                'old_status' => $oldStatus,
                'new_status' => $command->newStatus,
                'changed_by' => $command->userId,
                'reason' => $command->reason,
            ]);
            
            return $attendance->fresh();
        });
    }
    
    /**
     * Get attendance record with school scope
     * 
     * @throws \Exception if not found
     */
    private function getAttendance(int $attendanceId): Attendance
    {
        $attendance = Attendance::find($attendanceId);
        
        if (!$attendance) {
            throw new \Exception("Attendance record not found: {$attendanceId}");
        }
        
        // Verify user has access to this school's data
        $user = Auth::user();
        if ($user && $user->school_id && $user->school_id !== $attendance->school_id) {
            throw new \Exception("Unauthorized: Cannot access attendance from another school");
        }
        
        return $attendance;
    }
    
    /**
     * Validate state transition using state machine
     * 
     * @throws \Exception if transition is invalid
     */
    private function validateStateTransition(Attendance $attendance, string $newStatus): void
    {
        // If attendance has state machine, use it for validation
        if (method_exists($attendance, 'canTransitionTo')) {
            if (!$attendance->canTransitionTo($newStatus)) {
                throw new \Exception(
                    "Invalid state transition from '{$attendance->status}' to '{$newStatus}'"
                );
            }
        }
        
        // Additional business rules
        $this->validateBusinessRules($attendance, $newStatus);
    }
    
    /**
     * Validate business rules for status change
     * 
     * @throws \Exception if business rules violated
     */
    private function validateBusinessRules(Attendance $attendance, string $newStatus): void
    {
        // Cannot change status if attendance is too old (e.g., > 7 days)
        $maxDaysForEdit = 7;
        $daysSinceAttendance = now()->diffInDays($attendance->attendance_date);
        
        if ($daysSinceAttendance > $maxDaysForEdit) {
            throw new \Exception(
                "Cannot change attendance status older than {$maxDaysForEdit} days"
            );
        }
        
        // Cannot change to same status
        if ($attendance->status === $newStatus) {
            throw new \Exception("Attendance is already marked as '{$newStatus}'");
        }
        
        // Additional validations can be added here
    }
    
    /**
     * Update attendance status
     */
    private function updateStatus(Attendance $attendance, string $newStatus): Attendance
    {
        // Use state machine transition if available
        if (method_exists($attendance, 'transitionTo')) {
            $attendance->transitionTo($newStatus);
        } else {
            // Fallback to direct update
            $attendance->status = $newStatus;
            $attendance->save();
        }
        
        return $attendance;
    }
    
    /**
     * Create audit log entry
     */
    private function createAuditLog(
        Attendance $attendance,
        int $userId,
        string $oldStatus,
        string $newStatus,
        ?string $reason
    ): void {
        AuditLog::create([
            'user_id' => $userId,
            'action' => 'attendance.status_changed',
            'auditable_type' => Attendance::class,
            'auditable_id' => $attendance->id,
            'old_values' => json_encode([
                'status' => $oldStatus,
            ]),
            'new_values' => json_encode([
                'status' => $newStatus,
            ]),
            'metadata' => json_encode([
                'student_id' => $attendance->student_id,
                'attendance_date' => $attendance->attendance_date->toDateString(),
                'reason' => $reason,
            ]),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
