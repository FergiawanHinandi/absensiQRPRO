<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine if user can view another user (Student/Teacher)
     */
    public function view(User $authenticatedUser, User $targetUser): bool
    {
        // Super admin can view all
        if ($this->isSuperAdmin($authenticatedUser)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($authenticatedUser->school_id !== $targetUser->school_id) {
            return false;
        }

        // Students can only view themselves
        if ($authenticatedUser->role_type === 'student') {
            return $authenticatedUser->id === $targetUser->id;
        }

        // Parents can view their children
        if ($authenticatedUser->role_type === 'parent') {
            return $this->isParentOfStudent($authenticatedUser, $targetUser);
        }

        // Teachers, admins can view all in their school
        return in_array($authenticatedUser->role_type, [
            'teacher',
            'homeroom_teacher',
            'admin',
            'school_admin',
            'principal',
        ]);
    }

    /**
     * Determine if user can create users (students/teachers)
     */
    public function create(User $user): bool
    {
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal',
            'super_admin',
        ]);
    }

    /**
     * Determine if user can update another user
     */
    public function update(User $authenticatedUser, User $targetUser): bool
    {
        // Super admin can update all
        if ($this->isSuperAdmin($authenticatedUser)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($authenticatedUser->school_id !== $targetUser->school_id) {
            return false;
        }

        // Students can update their own profile (limited fields)
        if ($authenticatedUser->role_type === 'student') {
            return $authenticatedUser->id === $targetUser->id;
        }

        // Only admins can update users
        return in_array($authenticatedUser->role_type, [
            'admin',
            'school_admin',
            'principal',
        ]);
    }

    /**
     * Determine if user can delete another user
     */
    public function delete(User $authenticatedUser, User $targetUser): bool
    {
        // Super admin can delete all
        if ($this->isSuperAdmin($authenticatedUser)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($authenticatedUser->school_id !== $targetUser->school_id) {
            return false;
        }

        // Only school admins can delete
        return in_array($authenticatedUser->role_type, [
            'admin',
            'school_admin',
            'super_admin',
        ]);
    }

    /**
     * Determine if user can view list of users
     */
    public function viewAny(User $user): bool
    {
        // All authenticated users can view lists (filtered by school)
        return in_array($user->role_type, [
            'teacher',
            'homeroom_teacher',
            'admin',
            'school_admin',
            'principal',
            'super_admin',
        ]);
    }

    /**
     * Check if user is super admin
     */
    private function isSuperAdmin(User $user): bool
    {
        $superAdminRole = config('permission.super_admin_role', 'super_admin');

        if (method_exists($user, 'hasRole') && $user->hasRole($superAdminRole)) {
            return true;
        }

        return $user->role_type === $superAdminRole;
    }

    /**
     * Check if user is parent of student
     */
    private function isParentOfStudent(User $parent, User $student): bool
    {
        if ($parent->role_type !== 'parent' || $student->role_type !== 'student') {
            return false;
        }

        // Check parent_students relationship
        return \DB::table('student_parents')
            ->where('parent_id', $parent->id)
            ->where('student_id', $student->id)
            ->exists();
    }
}
