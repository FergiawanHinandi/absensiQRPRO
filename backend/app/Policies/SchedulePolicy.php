<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    /**
     * Determine if user can view schedule
     */
    public function view(User $user, Schedule $schedule): bool
    {
        // Super admin can view all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $schedule->school_id) {
            return false;
        }

        // Students can view schedules for their class
        if ($user->role_type === 'student') {
            return $this->isStudentInScheduleClass($user, $schedule);
        }

        // Teachers can view their own schedules
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            return $schedule->teacher_id === $user->id;
        }

        // Admins can view all schedules in their school
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal'
        ]);
    }

    /**
     * Determine if user can create schedules
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
     * Determine if user can update schedule
     */
    public function update(User $user, Schedule $schedule): bool
    {
        // Super admin can update all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $schedule->school_id) {
            return false;
        }

        // Only admins can update schedules
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal'
        ]);
    }

    /**
     * Determine if user can delete schedule
     */
    public function delete(User $user, Schedule $schedule): bool
    {
        // Super admin can delete all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $schedule->school_id) {
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
     * Determine if user can view any schedules
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role_type, [
            'student',
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

    /**
     * Check if student is in the schedule's class
     */
    private function isStudentInScheduleClass(User $student, Schedule $schedule): bool
    {
        return \DB::table('class_students')
            ->where('student_id', $student->id)
            ->where('class_id', $schedule->class_id)
            ->where('status', 'active')
            ->exists();
    }
}
