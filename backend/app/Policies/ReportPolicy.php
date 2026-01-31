<?php

namespace App\Policies;

use App\Models\AttendanceReport;
use App\Models\User;

class ReportPolicy
{
    /**
     * Determine if user can view report
     */
    public function view(User $user, AttendanceReport $report): bool
    {
        // Super admin can view all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $report->school_id) {
            return false;
        }

        // Only admins and teachers can view reports
        return in_array($user->role_type, [
            'teacher',
            'homeroom_teacher',
            'admin',
            'school_admin',
            'principal'
        ]);
    }

    /**
     * Determine if user can create reports
     */
    public function create(User $user): bool
    {
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal',
            'super_admin'
        ]);
    }

    /**
     * Determine if user can generate reports
     */
    public function generate(User $user): bool
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
     * Determine if user can delete report
     */
    public function delete(User $user, AttendanceReport $report): bool
    {
        // Super admin can delete all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $report->school_id) {
            return false;
        }

        // Only school admins can delete
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'super_admin'
        ]);
    }

    /**
     * Determine if user can view any reports
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
}
