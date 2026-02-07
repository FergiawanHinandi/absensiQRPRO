<?php

namespace App\Policies;

use App\Models\StudentPermission;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * StudentPermissionPolicy - Authorization for Student Leave/Permission Requests
 *
 * SECURITY MODEL:
 * - School isolation enforced on all operations
 * - Students can only view/create their own permissions
 * - Parents can view their children's permissions
 * - Teachers (especially homeroom) can approve/reject for their classes
 * - Admins have full access within their school
 *
 * ROLE HIERARCHY:
 * 1. super_admin     → Full access to ALL schools
 * 2. school_admin    → Full access to own school
 * 3. principal       → View all + manage for own school
 * 4. homeroom_teacher→ Approve/reject for their classes
 * 5. teacher         → View for their classes
 * 6. student         → View/create only their own
 * 7. parent          → View only linked children's permissions
 */
class StudentPermissionPolicy
{
    use HandlesAuthorization;

    private const SUPER_ADMIN = 'super_admin';
    private const SCHOOL_ADMIN = 'school_admin';
    private const ADMIN = 'admin';
    private const PRINCIPAL = 'principal';
    private const HOMEROOM_TEACHER = 'homeroom_teacher';
    private const TEACHER = 'teacher';
    private const STUDENT = 'student';
    private const PARENT = 'parent';

    /**
     * Pre-authorization checks
     */
    public function before(User $user, string $ability): ?bool
    {
        // Super admin bypass
        if ($user->role_type === self::SUPER_ADMIN) {
            return true;
        }

        // Inactive users cannot do anything
        if (!$user->is_active) {
            return false;
        }

        return null;
    }

    /**
     * Can user view any permissions (list view)?
     */
    public function viewAny(User $user): Response
    {
        $allowedRoles = [
            self::SUPER_ADMIN,
            self::SCHOOL_ADMIN,
            self::ADMIN,
            self::PRINCIPAL,
            self::HOMEROOM_TEACHER,
            self::TEACHER,
            self::STUDENT,
            self::PARENT,
        ];

        if (in_array($user->role_type, $allowedRoles)) {
            return Response::allow();
        }

        return Response::deny('Anda tidak memiliki akses untuk melihat data izin.');
    }

    /**
     * Can user view a specific permission?
     */
    public function view(User $user, StudentPermission $permission): Response
    {
        // School isolation
        if ($user->school_id !== $permission->school_id) {
            return Response::deny('Anda tidak dapat mengakses data dari sekolah lain.');
        }

        // Students can only view their own
        if ($user->role_type === self::STUDENT) {
            if ($user->id === $permission->student_id) {
                return Response::allow();
            }
            return Response::deny('Anda hanya dapat melihat izin Anda sendiri.');
        }

        // Parents can only view their children's
        if ($user->role_type === self::PARENT) {
            if ($this->isParentOfStudent($user, $permission->student_id)) {
                return Response::allow();
            }
            return Response::deny('Anda hanya dapat melihat izin anak Anda.');
        }

        // Teachers can view their class students
        if (in_array($user->role_type, [self::TEACHER, self::HOMEROOM_TEACHER])) {
            if ($this->isTeacherOfClass($user, $permission->class_id)) {
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
     * Can user create a permission request?
     */
    public function create(User $user): Response
    {
        // Students can create for themselves
        if ($user->role_type === self::STUDENT) {
            return Response::allow();
        }

        // Teachers and admins can create for students
        if (in_array($user->role_type, [
            self::TEACHER,
            self::HOMEROOM_TEACHER,
            self::ADMIN,
            self::SCHOOL_ADMIN,
        ])) {
            return Response::allow();
        }

        return Response::deny('Anda tidak dapat membuat pengajuan izin.');
    }

    /**
     * Can user update a permission (edit before approval)?
     */
    public function update(User $user, StudentPermission $permission): Response
    {
        // School isolation
        if ($user->school_id !== $permission->school_id) {
            return Response::deny('Anda tidak dapat mengubah data dari sekolah lain.');
        }

        // Cannot update if already approved/rejected
        if ($permission->status !== 'pending') {
            return Response::deny('Izin yang sudah diproses tidak dapat diubah.');
        }

        // Students can only update their own pending requests
        if ($user->role_type === self::STUDENT) {
            if ($user->id === $permission->student_id) {
                return Response::allow();
            }
            return Response::deny('Anda hanya dapat mengubah izin Anda sendiri.');
        }

        // Admins can update any
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN])) {
            return Response::allow();
        }

        return Response::deny('Akses ditolak.');
    }

    /**
     * Can user approve a permission request?
     */
    public function approve(User $user, StudentPermission $permission): Response
    {
        // School isolation
        if ($user->school_id !== $permission->school_id) {
            return Response::deny('Anda tidak dapat menyetujui izin dari sekolah lain.');
        }

        // Must be pending
        if ($permission->status !== 'pending') {
            return Response::deny('Izin sudah diproses sebelumnya.');
        }

        // Homeroom teachers can approve their class
        if ($user->role_type === self::HOMEROOM_TEACHER) {
            if ($this->isHomeroomOfClass($user, $permission->class_id)) {
                return Response::allow();
            }
            return Response::deny('Anda bukan wali kelas siswa ini.');
        }

        // Admins and principals can approve any
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN, self::PRINCIPAL])) {
            return Response::allow();
        }

        return Response::deny('Hanya wali kelas atau admin yang dapat menyetujui izin.');
    }

    /**
     * Can user reject a permission request?
     */
    public function reject(User $user, StudentPermission $permission): Response
    {
        // Same rules as approve
        return $this->approve($user, $permission);
    }

    /**
     * Can user delete a permission?
     */
    public function delete(User $user, StudentPermission $permission): Response
    {
        // School isolation
        if ($user->school_id !== $permission->school_id) {
            return Response::deny('Anda tidak dapat menghapus data dari sekolah lain.');
        }

        // Students can delete their own pending requests
        if ($user->role_type === self::STUDENT) {
            if ($user->id === $permission->student_id && $permission->status === 'pending') {
                return Response::allow();
            }
            return Response::deny('Anda hanya dapat membatalkan izin Anda yang masih pending.');
        }

        // Only admins can delete
        if (in_array($user->role_type, [self::ADMIN, self::SCHOOL_ADMIN])) {
            return Response::allow();
        }

        return Response::deny('Hanya administrator yang dapat menghapus data izin.');
    }

    // =========================================================================
    // HELPER METHODS
    // =========================================================================

    /**
     * Check if parent is linked to student
     */
    private function isParentOfStudent(User $parent, int $studentId): bool
    {
        return \DB::table('parent_students')
            ->where('parent_id', $parent->id)
            ->where('student_id', $studentId)
            ->exists();
    }

    /**
     * Check if teacher teaches the class
     */
    private function isTeacherOfClass(User $teacher, ?int $classId): bool
    {
        if (!$classId) {
            return false;
        }

        return \DB::table('schedules')
            ->where('teacher_id', $teacher->id)
            ->where('class_id', $classId)
            ->exists();
    }

    /**
     * Check if teacher is homeroom of the class
     */
    private function isHomeroomOfClass(User $teacher, ?int $classId): bool
    {
        if (!$classId) {
            return false;
        }

        return \DB::table('teacher_roles')
            ->where('teacher_id', $teacher->id)
            ->where('homeroom_class_id', $classId)
            ->where('is_homeroom_teacher', true)
            ->exists();
    }
}
