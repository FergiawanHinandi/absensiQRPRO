<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * CRITICAL: Student Policy for proper authorization
 *
 * FIXES:
 * - Authorization before data loading
 * - School-scoped access control
 * - Role-based permissions
 */
class StudentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if user can view any students
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role_type, [
            'super_admin',
            'school_admin',
            'principal',
            'vice_principal',
            'teacher',
            'homeroom_teacher',
        ]);
    }

    /**
     * Determine if user can view specific student
     */
    public function view(User $user, User $student): bool
    {
        // Super admin can view any student
        if ($user->role_type === 'super_admin') {
            return true;
        }

        // Must be from same school
        if ($user->school_id !== $student->school_id) {
            return false;
        }

        // Student can only view their own data
        if ($user->role_type === 'student') {
            return $user->id === $student->id;
        }

        // Parent can only view their children
        if ($user->role_type === 'parent') {
            return $user->children()->where('id', $student->id)->exists();
        }

        // School staff can view students in their school
        return in_array($user->role_type, [
            'school_admin',
            'principal',
            'vice_principal',
            'teacher',
            'homeroom_teacher',
        ]);
    }

    /**
     * Determine if user can create students
     */
    public function create(User $user): bool
    {
        return in_array($user->role_type, [
            'super_admin',
            'school_admin',
            'principal',
        ]);
    }

    /**
     * Determine if user can update student
     */
    public function update(User $user, User $student): bool
    {
        // Super admin can update any student
        if ($user->role_type === 'super_admin') {
            return true;
        }

        // Must be from same school
        if ($user->school_id !== $student->school_id) {
            return false;
        }

        // Only school admin and principal can update students
        return in_array($user->role_type, [
            'school_admin',
            'principal',
        ]);
    }

    /**
     * Determine if user can delete student
     */
    public function delete(User $user, User $student): bool
    {
        // Super admin can delete any student
        if ($user->role_type === 'super_admin') {
            return true;
        }

        // Must be from same school
        if ($user->school_id !== $student->school_id) {
            return false;
        }

        // Only school admin can delete students
        return $user->role_type === 'school_admin';
    }

    /**
     * Determine if user can view student placements
     */
    public function viewPlacements(User $user): bool
    {
        return in_array($user->role_type, [
            'super_admin',
            'school_admin',
            'principal',
            'vice_principal',
        ]);
    }

    /**
     * Determine if user can update student placements
     */
    public function updatePlacements(User $user): bool
    {
        return in_array($user->role_type, [
            'super_admin',
            'school_admin',
            'principal',
        ]);
    }
}
