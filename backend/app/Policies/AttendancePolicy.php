<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * AttendancePolicy - Comprehensive Authorization for Attendance Records
 *
 * SECURITY MODEL:
 * - School isolation: Users can ONLY access data from their own school
 * - Role-based access: Different permissions per role
 * - Super admin bypass: Full access to all data
 *
 * ROLE HIERARCHY:
 * 1. super_admin     → Full access to ALL schools
 * 2. school_admin    → Full access to own school
 * 3. principal       → View all + manage reports for own school
 * 4. homeroom_teacher→ View/manage classes they're assigned to
 * 5. teacher         → View/manage schedules they teach
 * 6. student         → View only their own attendance
 * 7. parent          → View only linked children's attendance
 *
 * CRITICAL: This policy acts as LAST LINE OF DEFENSE even if:
 * - Global scopes are bypassed with withoutGlobalScope()
 * - Raw DB::table queries are used
 * - Joins don't include school_id filtering
 */
class AttendancePolicy
{
    use HandlesAuthorization;

    /**
     * Role constants for better maintainability
     */
    private const SUPER_ADMIN = 'super_admin';

    private const SCHOOL_ADMIN = 'school_admin';

    private const ADMIN = 'admin';

    private const PRINCIPAL = 'principal';

    private const HOMEROOM_TEACHER = 'homeroom_teacher';

    private const TEACHER = 'teacher';

    private const STUDENT = 'student';

    private const PARENT = 'parent';

    /**
     * Roles that can manage attendance (create/update/delete)
     */
    private const MANAGEMENT_ROLES = [
        self::SUPER_ADMIN,
        self::SCHOOL_ADMIN,
        self::ADMIN,
        self::PRINCIPAL,
        self::HOMEROOM_TEACHER,
        self::TEACHER,
    ];

    /**
     * Roles that can view attendance
     */
    private const VIEW_ROLES = [
        self::SUPER_ADMIN,
        self::SCHOOL_ADMIN,
        self::ADMIN,
        self::PRINCIPAL,
        self::HOMEROOM_TEACHER,
        self::TEACHER,
        self::STUDENT,
        self::PARENT,
    ];

    /**
     * Perform pre-authorization checks (before any specific method)
     *
     * This is called BEFORE every policy method.
     * Return null to fall through to specific method.
     */
    public function before(User $user, string $ability): ?bool
    {
        // Super admin bypasses checks, BUT historical protection must still apply
        // We let update/delete/forceDelete fall through to their specific methods
        if ($this->isSuperAdmin($user)) {
            if (in_array($ability, ['update', 'delete', 'forceDelete', 'restore'])) {
                return null;
            }

            return true;
        }

        // Inactive users cannot do anything
        if (! $user->is_active) {
            return false;
        }

        return null; // Fall through to specific method
    }

    /**
     * Determine if user can view any attendance records (list view)
     *
     * Used for: Index pages, dashboard widgets
     */
    public function viewAny(User $user): Response
    {
        if (in_array($user->role_type, self::VIEW_ROLES)) {
            return Response::allow();
        }

        return Response::deny('Anda tidak memiliki akses untuk melihat data absensi.');
    }

    /**
     * Determine if user can view a specific attendance record
     *
     * CRITICAL: Always enforce school isolation first
     */
    public function view(User $user, Attendance $attendance): Response
    {
        // SECURITY CHECK 1: School isolation
        if (! $this->isSameSchool($user, $attendance)) {
            return Response::deny('Anda tidak dapat mengakses data dari sekolah lain.');
        }

        // Students can only view their own attendance
        if ($user->role_type === self::STUDENT) {
            if ($user->id === $attendance->student_id) {
                return Response::allow();
            }

            return Response::deny('Anda hanya dapat melihat absensi Anda sendiri.');
        }

        // Parents can only view their children's attendance
        if ($user->role_type === self::PARENT) {
            if ($this->isParentOfStudent($user, $attendance->student_id)) {
                return Response::allow();
            }

            return Response::deny('Anda hanya dapat melihat absensi anak Anda.');
        }

        // Teachers can view attendance for their schedules/classes
        if (in_array($user->role_type, [self::TEACHER, self::HOMEROOM_TEACHER])) {
            if ($this->canTeacherViewAttendance($user, $attendance)) {
                return Response::allow();
            }

            return Response::deny('Anda tidak mengajar kelas ini.');
        }

        // Admins and principals can view all in their school
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN, self::PRINCIPAL])) {
            return Response::allow();
        }

        return Response::deny('Akses ditolak.');
    }

    /**
     * Determine if user can create attendance records
     *
     * Used for: Manual attendance input, QR scan recording
     */
    public function create(User $user): Response
    {
        if (in_array($user->role_type, self::MANAGEMENT_ROLES)) {
            return Response::allow();
        }

        // Students can create via QR scan (handled separately)
        if ($user->role_type === self::STUDENT) {
            return Response::allow();
        }

        return Response::deny('Anda tidak memiliki akses untuk membuat data absensi.');
    }

    /**
     * Determine if user can create manual attendance
     *
     * Used for: Teacher/Admin manual input form
     */
    public function createManual(User $user): Response
    {
        if (in_array($user->role_type, [
            self::TEACHER,
            self::HOMEROOM_TEACHER,
            self::ADMIN,
            self::SCHOOL_ADMIN,
        ])) {
            return Response::allow();
        }

        return Response::deny('Hanya guru dan admin yang dapat input absensi manual.');
    }

    /**
     * Determine if user can update an attendance record
     *
     * SECURITY: Only manual attendance can be updated
     */
    public function update(User $user, Attendance $attendance): Response
    {
        // SUPER ADMIN CHECK: Historical Data Protection
        if ($this->isSuperAdmin($user)) {
            if ($attendance->created_at < now()->subHours(24)) {
                return Response::deny('Super Admin cannot edit historical data (>24h old) for audit integrity.');
            }

            return Response::allow();
        }

        // SECURITY CHECK 1: School isolation
        if (! $this->isSameSchool($user, $attendance)) {
            return Response::deny('Anda tidak dapat mengubah data dari sekolah lain.');
        }

        // BUSINESS RULE: Only manual attendance can be updated
        if (! $attendance->is_manual) {
            return Response::deny('Absensi otomatis (via QR) tidak dapat diubah.');
        }

        // Teachers can only update their own classes/schedules
        if (in_array($user->role_type, [self::TEACHER, self::HOMEROOM_TEACHER])) {
            if ($this->canTeacherManageAttendance($user, $attendance)) {
                return Response::allow();
            }

            return Response::deny('Anda hanya dapat mengubah absensi kelas yang Anda ajar.');
        }

        // Admins can update any in their school
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN])) {
            return Response::allow();
        }

        return Response::deny('Akses ditolak.');
    }

    /**
     * Determine if user can delete an attendance record
     *
     * SECURITY: Highly restricted operation
     */
    public function delete(User $user, Attendance $attendance): Response
    {
        // SUPER ADMIN CHECK: Historical Data Protection
        if ($this->isSuperAdmin($user)) {
            if ($attendance->created_at < now()->subHours(24)) {
                return Response::deny('Super Admin cannot delete historical data (>24h old) for audit integrity.');
            }

            return Response::allow();
        }

        // SECURITY CHECK 1: School isolation
        if (! $this->isSameSchool($user, $attendance)) {
            return Response::deny('Anda tidak dapat menghapus data dari sekolah lain.');
        }

        // Only admins can delete
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN])) {
            return Response::allow();
        }

        return Response::deny('Hanya administrator yang dapat menghapus data absensi.');
    }

    /**
     * Determine if user can restore a soft-deleted attendance record
     */
    public function restore(User $user, Attendance $attendance): Response
    {
        // Same rules as delete
        return $this->delete($user, $attendance);
    }

    /**
     * Determine if user can permanently delete (force delete)
     *
     * SECURITY: Only super admin can force delete
     */
    public function forceDelete(User $user, Attendance $attendance): Response
    {
        if ($this->isSuperAdmin($user)) {
            if ($attendance->created_at < now()->subHours(24)) {
                return Response::deny('Super Admin cannot force delete historical data (>24h old).');
            }

            return Response::allow();
        }

        return Response::deny('Penghapusan permanen tidak diizinkan.');
    }

    /**
     * Determine if student can scan QR code
     */
    public function scan(User $user): Response
    {
        if ($user->role_type !== self::STUDENT) {
            return Response::deny('Hanya siswa yang dapat melakukan scan QR.');
        }

        if (! $user->is_active) {
            return Response::deny('Akun Anda tidak aktif.');
        }

        return Response::allow();
    }

    /**
     * Determine if teacher can scan student QR
     */
    public function scanStudent(User $user): Response
    {
        if (! in_array($user->role_type, [self::TEACHER, self::HOMEROOM_TEACHER])) {
            return Response::deny('Hanya guru yang dapat melakukan scan siswa.');
        }

        if (! $user->is_active) {
            return Response::deny('Akun Anda tidak aktif.');
        }

        return Response::allow();
    }

    /**
     * Determine if user can view attendance reports
     */
    public function viewReports(User $user): Response
    {
        if (in_array($user->role_type, [
            self::ADMIN,
            self::SCHOOL_ADMIN,
            self::PRINCIPAL,
            self::HOMEROOM_TEACHER,
        ])) {
            return Response::allow();
        }

        return Response::deny('Anda tidak memiliki akses ke laporan absensi.');
    }

    /**
     * Determine if user can export attendance data
     */
    public function export(User $user): Response
    {
        if (in_array($user->role_type, [
            self::ADMIN,
            self::SCHOOL_ADMIN,
            self::PRINCIPAL,
        ])) {
            return Response::allow();
        }

        return Response::deny('Hanya administrator yang dapat mengekspor data.');
    }

    /**
     * Determine if user can view attendance for a specific schedule
     */
    public function viewBySchedule(User $user, Schedule $schedule): Response
    {
        // School isolation
        if ($user->school_id !== $schedule->school_id) {
            return Response::deny('Anda tidak dapat mengakses jadwal dari sekolah lain.');
        }

        // Teacher must own the schedule
        if (in_array($user->role_type, [self::TEACHER, self::HOMEROOM_TEACHER])) {
            if ($schedule->teacher_id === $user->id) {
                return Response::allow();
            }

            return Response::deny('Anda bukan pengajar jadwal ini.');
        }

        // Admins can view all
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN, self::PRINCIPAL])) {
            return Response::allow();
        }

        return Response::deny('Akses ditolak.');
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if user is super admin
     */
    private function isSuperAdmin(User $user): bool
    {
        if ($user->role_type === self::SUPER_ADMIN) {
            return true;
        }

        // Check via Spatie if available
        if (method_exists($user, 'hasRole')) {
            return $user->hasRole(self::SUPER_ADMIN);
        }

        return false;
    }

    /**
     * Check if user belongs to same school as attendance
     */
    private function isSameSchool(User $user, Attendance $attendance): bool
    {
        return $user->school_id === $attendance->school_id;
    }

    /**
     * Check if parent is linked to the student
     */
    private function isParentOfStudent(User $parent, int $studentId): bool
    {
        // Assuming there's a parent_student pivot table
        return \DB::table('parent_students')
            ->where('parent_id', $parent->id)
            ->where('student_id', $studentId)
            ->exists();
    }

    /**
     * Check if teacher can view attendance (owns schedule or is homeroom)
     */
    private function canTeacherViewAttendance(User $teacher, Attendance $attendance): bool
    {
        // Method 1: Teacher owns the schedule
        if ($attendance->schedule && $attendance->schedule->teacher_id === $teacher->id) {
            return true;
        }

        // Method 2: Teacher is homeroom for the class
        if ($teacher->role_type === self::HOMEROOM_TEACHER) {
            $isHomeroom = \DB::table('teacher_roles')
                ->where('user_id', $teacher->id)
                ->where('class_id', $attendance->class_id)
                ->where('role_name', 'homeroom')
                ->exists();

            if ($isHomeroom) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if teacher can manage (update/delete) attendance
     */
    private function canTeacherManageAttendance(User $teacher, Attendance $attendance): bool
    {
        // Must own the schedule to manage
        if ($attendance->schedule && $attendance->schedule->teacher_id === $teacher->id) {
            return true;
        }

        // Homeroom teachers can manage their class
        if ($teacher->role_type === self::HOMEROOM_TEACHER) {
            return \DB::table('teacher_roles')
                ->where('user_id', $teacher->id)
                ->where('class_id', $attendance->class_id)
                ->where('role_name', 'homeroom')
                ->exists();
        }

        return false;
    }
}
