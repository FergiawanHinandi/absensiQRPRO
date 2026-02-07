<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

abstract class TenantPolicy
{
    /**
     * Base tenant isolation check
     * Super admin bypass only at policy layer
     */
    protected function checkTenantAccess(User $user, Model $model): bool
    {
        // Super admin bypass - only allowed at policy layer
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check if model has school_id
        if (!isset($model->school_id)) {
            return false;
        }

        // User must belong to the same school
        return $user->school_id === $model->school_id;
    }

    /**
     * Check if user can access school-specific data
     */
    protected function checkSchoolAccess(User $user, int $schoolId): bool
    {
        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // User must belong to the same school
        return $user->school_id === $schoolId;
    }

    /**
     * Apply school_id filter to queries
     */
    protected function applyTenantFilter($query, User $user)
    {
        // Super admin bypass - no filtering needed
        if ($user->hasRole('super_admin')) {
            return $query;
        }

        // Apply school_id filter for all other users
        return $query->where('school_id', $user->school_id);
    }

    /**
     * Validate tenant context for bulk operations
     */
    protected function validateTenantContext(User $user, array $data): bool
    {
        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check if data contains school_id
        if (isset($data['school_id'])) {
            return $data['school_id'] === $user->school_id;
        }

        // If no school_id in data, ensure user's school_id is set
        return true;
    }
}
