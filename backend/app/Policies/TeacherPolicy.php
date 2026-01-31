<?php

namespace App\Policies;

use App\Models\User;

/**
 * TeacherPolicy - Tenant Isolation for Teachers
 * 
 * Ensures users can only access teacher records within their school.
 * Teachers represented as User model with role_type='teacher' or 'homeroom_teacher'.
 */
class TeacherPolicy
{
    /**
     * Determine if user can view any teachers
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role_type, [
            'teacher',
            'homeroom_teacher',
            'admin',
            'school_admin',
            'principal',
            'super_admin'
        ]);
    }

    /**
     * Determine if user can view a specific teacher
     * CRITICAL: Always check school_id first
     */
    public function view(User $user, User $teacher): bool
    {
        // Super admin bypass
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // CRITICAL SECURITY CHECK - Same school only
        if ($user->school_id !== $teacher->school_id) {
            return false;
        }

        // Teachers can view their own profile
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            if ($user->id === $teacher->id) {
                return true;
            }
            // Teachers can view other teachers in their school (for collaboration)
            return true;
        }

        // Admins can view all teachers in their school
        return in_array($user->role_type, ['admin', 'school_admin', 'principal']);
    }

    /**
     * Determine if user can create teachers
     */
    public function create(User $user): bool
    {
        return in_array($user->role_type, ['admin', 'school_admin', 'principal', 'super_admin']);
    }

    /**
     * Determine if user can update a teacher
     * CRITICAL: Always check school_id first
     */
    public function update(User $user, User $teacher): bool
    {
        // Super admin bypass
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // CRITICAL SECURITY CHECK - Same school only
        if ($user->school_id !== $teacher->school_id) {
            return false;
        }

        // Teachers can update their own profile (limited fields)
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher']) && $user->id === $teacher->id) {
            return true;
        }

        // Only admins can update teacher records
        return in_array($user->role_type, ['admin', 'school_admin', 'principal']);
    }

    /**
     * Determine if user can delete a teacher
     * CRITICAL: Always check school_id first
     */
    public function delete(User $user, User $teacher): bool
    {
        // Super admin bypass
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // CRITICAL SECURITY CHECK - Same school only
        if ($user->school_id !== $teacher->school_id) {
            return false;
        }

        // Only admins can delete teachers
        return in_array($user->role_type, ['admin', 'school_admin', 'principal']);
    }

    /**
     * Check if user is super admin
     */
    private function isSuperAdmin(User $user): bool
    {
        return $user->role_type === 'super_admin';
    }
}
