<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Student Card Policy - Strict Role-Based Access Control
 * 
 * BUSINESS RULE: Only School Admin can manage student QR cards
 * Teachers must NEVER have access to this functionality
 */
class StudentCardPolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can generate student QR cards
     * 
     * RULE: Only school_admin role allowed
     */
    public function generate(User $user): bool
    {
        // CRITICAL: Only school_admin can generate cards
        if ($user->role_type !== 'school_admin') {
            \Log::warning('Unauthorized student card generation attempt', [
                'user_id' => $user->id,
                'role' => $user->role_type,
                'school_id' => $user->school_id,
                'action' => 'generate',
                'ip' => request()->ip(),
            ]);
            return false;
        }

        // Additional check: User must be active
        if (!$user->is_active) {
            \Log::warning('Inactive user attempted card generation', [
                'user_id' => $user->id,
                'school_id' => $user->school_id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can regenerate student QR cards
     * 
     * RULE: Only school_admin role allowed
     */
    public function regenerate(User $user): bool
    {
        // CRITICAL: Only school_admin can regenerate cards
        if ($user->role_type !== 'school_admin') {
            \Log::warning('Unauthorized student card regeneration attempt', [
                'user_id' => $user->id,
                'role' => $user->role_type,
                'school_id' => $user->school_id,
                'action' => 'regenerate',
                'ip' => request()->ip(),
            ]);
            return false;
        }

        // Additional check: User must be active
        if (!$user->is_active) {
            \Log::warning('Inactive user attempted card regeneration', [
                'user_id' => $user->id,
                'school_id' => $user->school_id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can deactivate student QR cards
     * 
     * RULE: Only school_admin role allowed
     */
    public function deactivate(User $user): bool
    {
        // CRITICAL: Only school_admin can deactivate cards
        if ($user->role_type !== 'school_admin') {
            \Log::warning('Unauthorized student card deactivation attempt', [
                'user_id' => $user->id,
                'role' => $user->role_type,
                'school_id' => $user->school_id,
                'action' => 'deactivate',
                'ip' => request()->ip(),
            ]);
            return false;
        }

        // Additional check: User must be active
        if (!$user->is_active) {
            \Log::warning('Inactive user attempted card deactivation', [
                'user_id' => $user->id,
                'school_id' => $user->school_id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Determine if the user can view student cards
     * 
     * RULE: Only school_admin and principal can view
     */
    public function view(User $user): bool
    {
        return in_array($user->role_type, ['school_admin', 'principal']) && $user->is_active;
    }

    /**
     * Determine if the user can view any student cards
     * 
     * RULE: Only school_admin and principal can view
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role_type, ['school_admin', 'principal']) && $user->is_active;
    }

    /**
     * CRITICAL: Explicitly deny all card management for teachers
     * This method is called for any undefined policy method
     */
    public function __call($method, $parameters)
    {
        $user = $parameters[0] ?? null;
        
        if ($user && in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            \Log::critical('Teacher attempted to access student card functionality', [
                'user_id' => $user->id,
                'role' => $user->role_type,
                'method' => $method,
                'school_id' => $user->school_id,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
            
            // This is a security violation - teachers should never access this
            return false;
        }

        // Default deny for any other undefined methods
        return false;
    }
}