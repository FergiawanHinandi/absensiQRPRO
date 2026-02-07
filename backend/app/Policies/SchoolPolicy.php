<?php

namespace App\Policies;

use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    /**
     * Determine if user can view any schools
     */
    public function viewAny(User $user): bool
    {
        // Super admin can view all schools
        return $user->role_type === 'super_admin';
    }

    /**
     * Determine if user can view specific school
     */
    public function view(User $user, School $school): bool
    {
        // Super admin can view any school
        if ($user->role_type === 'super_admin') {
            return true;
        }

        // Users can only view their own school
        return $user->school_id === $school->id;
    }

    /**
     * Determine if user can create schools
     */
    public function create(User $user): bool
    {
        // Only super admin can create schools
        return $user->role_type === 'super_admin';
    }

    /**
     * Determine if user can update school
     */
    public function update(User $user, School $school): bool
    {
        // Super admin can update any school
        if ($user->role_type === 'super_admin') {
            return true;
        }

        // School admin can update their own school
        if ($user->role_type === 'school_admin' && $user->school_id === $school->id) {
            return true;
        }

        return false;
    }

    /**
     * Determine if user can delete school
     */
    public function delete(User $user, School $school): bool
    {
        // Only super admin can delete schools
        return $user->role_type === 'super_admin';
    }
}
