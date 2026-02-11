<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Attendance;
use App\Models\Schedule;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Support\Facades\Log;

/**
 * AttendancePolicy - Zero Trust Multi-Tenant Authorization
 * 
 * SECURITY PRINCIPLES:
 * 1. ALWAYS check school_id (tenant isolation)
 * 2. NEVER trust client-provided IDs without validation
 * 3. Log all authorization failures for audit
 * 4. Fail closed - deny by default
 * 
 * ROLE HIERARCHY:
 * - super_admin: All access (bypass tenant restriction)
 * - school_admin: Full school access
 * - principal/vice_principal: View-only school-wide access
 * - teacher/homeroom_teacher: Own schedules/classes only
 * - student: Own records only
 * - parent: Linked children only
 * 
 * IDOR PROTECTION:
 * - All methods MUST validate school_id matches user's school_id
 * - Teacher methods MUST validate ownership (teacher_id, class_id)
 * - Student/Parent methods MUST validate relationship
 */
class AttendancePolicy
{
    use HandlesAuthorization;

    /**
     * Super admin bypass - runs BEFORE all other policy methods
     * 
     * WARNING: Super admin actions are logged for audit compliance
     */
    public function before(User $user, string $ability): ?bool
    {
        if ($user->role_type === 'super_admin') {
            Log::channel('audit')->info('super_admin_policy_bypass', [
                'user_id' => $user->id,
                'ability' => $ability,
                'timestamp' => now()->toIso8601String(),
            ]);
            return true;
        }
        
        return null; // Fall through to specific policy method
    }

    /**
     * Can user view the attendance list?
     * 
     * Used for: index endpoints
     */
    public function viewAny(User $user): bool
    {
        // Only active users can view
        if (!$user->is_active) {
            return false;
        }
        
        return in_array($user->role_type, [
            'student',        // Own records
            'parent',         // Children's records
            'teacher',        // Own schedules
            'homeroom_teacher',
            'principal',
            'vice_principal',
            'admin',
            'school_admin',
        ]);
    }

    /**
     * Can user view a specific attendance record?
     * 
     * IDOR Protection: Validates tenant + ownership
     */
    public function view(User $user, Attendance $attendance): bool
    {
        // CRITICAL: Tenant isolation check
        if ($user->school_id !== $attendance->school_id) {
            $this->logUnauthorizedAccess($user, 'view', $attendance, 'tenant_mismatch');
            return false;
        }

        return match ($user->role_type) {
            // Student can only view their own attendance
            'student' => $user->id === $attendance->student_id,
            
            // Parent can view their linked children's attendance
            'parent' => $this->isParentOfStudent($user, $attendance->student_id),
            
            // Teacher can view attendance from their schedules or homeroom class
            'teacher', 'homeroom_teacher' => $this->canTeacherViewAttendance($user, $attendance),
            
            // Admins can view all within their school
            'admin', 'school_admin', 'principal', 'vice_principal' => true,
            
            default => false,
        };
    }

    /**
     * Can user create attendance (Student QR scan)
     */
    public function create(User $user): bool
    {
        // Only active students can scan QR
        return $user->is_active && $user->role_type === 'student';
    }

    /**
     * Can user create manual attendance entry?
     * 
     * IDOR Protection: Validates schedule ownership
     */
    public function manualEntry(User $user, Schedule $schedule): bool
    {
        // CRITICAL: Tenant isolation
        if ($user->school_id !== $schedule->school_id) {
            $this->logUnauthorizedAccess($user, 'manualEntry', $schedule, 'tenant_mismatch');
            return false;
        }

        return match ($user->role_type) {
            // Teacher can only create for their OWN schedules
            'teacher' => $user->id === $schedule->teacher_id,
            
            // Homeroom teacher can create for homeroom schedules
            'homeroom_teacher' => $user->id === $schedule->teacher_id 
                || $this->isHomeroomTeacherOfClass($user, $schedule->class_id),
            
            // Admins can create for any schedule in their school
            'admin', 'school_admin' => true,
            
            default => false,
        };
    }

    /**
     * Can user update an attendance record?
     * 
     * IDOR Protection: Validates tenant + ownership chain
     */
    public function update(User $user, Attendance $attendance): bool
    {
        // CRITICAL: Tenant isolation
        if ($user->school_id !== $attendance->school_id) {
            $this->logUnauthorizedAccess($user, 'update', $attendance, 'tenant_mismatch');
            return false;
        }

        return match ($user->role_type) {
            // Teacher can update attendance from their schedules
            'teacher' => $this->isTeacherOfSchedule($user, $attendance->schedule_id),
            
            // Homeroom teacher can update for their homeroom class
            'homeroom_teacher' => $this->isTeacherOfSchedule($user, $attendance->schedule_id)
                || $this->isHomeroomTeacherOfAttendance($user, $attendance),
            
            // Admins can update any attendance in their school
            'admin', 'school_admin' => true,
            
            default => false,
        };
    }

    /**
     * Can user delete (soft) an attendance record?
     */
    public function delete(User $user, Attendance $attendance): bool
    {
        // CRITICAL: Tenant isolation
        if ($user->school_id !== $attendance->school_id) {
            $this->logUnauthorizedAccess($user, 'delete', $attendance, 'tenant_mismatch');
            return false;
        }

        // Only admins can delete attendance records
        return in_array($user->role_type, ['admin', 'school_admin']);
    }

    /**
     * Can user restore a soft-deleted attendance?
     */
    public function restore(User $user, Attendance $attendance): bool
    {
        return $this->delete($user, $attendance);
    }

    /**
     * Can user permanently delete (force delete)?
     * 
     * DANGER: Should rarely be allowed - audit trail destruction
     */
    public function forceDelete(User $user, Attendance $attendance): bool
    {
        // Force delete is NEVER allowed at policy level
        // Even super_admin bypass is logged and should be reviewed
        return false;
    }

    /**
     * Can user view attendance reports?
     */
    public function viewReports(User $user): bool
    {
        return in_array($user->role_type, [
            'teacher',
            'homeroom_teacher',
            'principal',
            'vice_principal',
            'admin',
            'school_admin',
        ]);
    }

    /**
     * Can user export attendance data?
     */
    public function export(User $user): bool
    {
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal',
        ]);
    }

    /**
     * Can user view attendance by schedule?
     * 
     * IDOR Protection: Validates schedule ownership
     */
    public function viewBySchedule(User $user, Schedule $schedule): bool
    {
        // CRITICAL: Tenant isolation
        if ($user->school_id !== $schedule->school_id) {
            $this->logUnauthorizedAccess($user, 'viewBySchedule', $schedule, 'tenant_mismatch');
            return false;
        }

        return match ($user->role_type) {
            'teacher' => $user->id === $schedule->teacher_id,
            'homeroom_teacher' => $user->id === $schedule->teacher_id 
                || $this->isHomeroomTeacherOfClass($user, $schedule->class_id),
            'admin', 'school_admin', 'principal', 'vice_principal' => true,
            default => false,
        };
    }

    // =========================================================================
    // HELPER METHODS - IDOR Protection Queries
    // =========================================================================

    /**
     * Check if user is the parent of a specific student
     */
    private function isParentOfStudent(User $parent, int $studentId): bool
    {
        return $parent->children()
            ->where('users.id', $studentId)
            ->where('users.school_id', $parent->school_id) // Extra tenant check
            ->exists();
    }

    /**
     * Check if teacher can view this attendance (schedule or homeroom)
     */
    private function canTeacherViewAttendance(User $teacher, Attendance $attendance): bool
    {
        // Check if teacher owns the schedule
        if ($this->isTeacherOfSchedule($teacher, $attendance->schedule_id)) {
            return true;
        }
        
        // Check if homeroom teacher of the student's class
        return $this->isHomeroomTeacherOfAttendance($teacher, $attendance);
    }

    /**
     * Check if user is the teacher of a specific schedule
     */
    private function isTeacherOfSchedule(User $teacher, ?int $scheduleId): bool
    {
        if (!$scheduleId) {
            return false;
        }
        
        return Schedule::where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id) // Extra tenant check
            ->exists();
    }

    /**
     * Check if user is homeroom teacher of a specific class
     */
    private function isHomeroomTeacherOfClass(User $teacher, ?int $classId): bool
    {
        if (!$classId) {
            return false;
        }
        
        return \App\Models\ClassModel::where('id', $classId)
            ->where('homeroom_teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id) // Extra tenant check
            ->exists();
    }

    /**
     * Check if user is homeroom teacher of the student's class in this attendance
     */
    private function isHomeroomTeacherOfAttendance(User $teacher, Attendance $attendance): bool
    {
        // Load the student's class through attendance
        $attendance->loadMissing('student.classes');
        
        foreach ($attendance->student->classes ?? [] as $class) {
            if ($class->homeroom_teacher_id === $teacher->id 
                && $class->school_id === $teacher->school_id) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Log unauthorized access attempts for security monitoring
     */
    private function logUnauthorizedAccess(User $user, string $ability, $resource, string $reason): void
    {
        Log::channel('security')->warning('attendance_policy_denied', [
            'user_id' => $user->id,
            'user_school_id' => $user->school_id,
            'user_role' => $user->role_type,
            'ability' => $ability,
            'resource_type' => get_class($resource),
            'resource_id' => $resource->id ?? null,
            'resource_school_id' => $resource->school_id ?? null,
            'reason' => $reason,
            'timestamp' => now()->toIso8601String(),
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
