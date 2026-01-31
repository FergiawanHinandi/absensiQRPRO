<?php

namespace App\Policies;

use App\Models\ClassModel;
use App\Models\User;

class ClassPolicy
{
    /**
     * Determine if user can view class
     */
    public function view(User $user, ClassModel $class): bool
    {
        // Super admin can view all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $class->school_id) {
            return false;
        }

        // Students can view their own class
        if ($user->role_type === 'student') {
            return $this->isStudentInClass($user, $class);
        }

        // Teachers can view classes they teach
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            return $this->isTeacherOfClass($user, $class);
        }

        // Admins can view all classes in their school
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal'
        ]);
    }

    /**
     * Determine if user can create classes
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
     * Determine if user can update class
     */
    public function update(User $user, ClassModel $class): bool
    {
        // Super admin can update all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $class->school_id) {
            return false;
        }

        // Only admins can update classes
        return in_array($user->role_type, [
            'admin',
            'school_admin',
            'principal'
        ]);
    }

    /**
     * Determine if user can delete class
     */
    public function delete(User $user, ClassModel $class): bool
    {
        // Super admin can delete all
        if ($this->isSuperAdmin($user)) {
            return true;
        }

        // Same school only - CRITICAL SECURITY CHECK
        if ($user->school_id !== $class->school_id) {
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
     * Determine if user can view any classes
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
     * Check if student is in class
     */
    private function isStudentInClass(User $student, ClassModel $class): bool
    {
        return \DB::table('class_students')
            ->where('student_id', $student->id)
            ->where('class_id', $class->id)
            ->where('status', 'active')
            ->exists();
    }

    /**
     * Check if teacher teaches this class
     */
    private function isTeacherOfClass(User $teacher, ClassModel $class): bool
    {
        // Check if homeroom teacher
        if ($class->homeroom_teacher_id === $teacher->id) {
            return true;
        }

        // Check if teaches any subject in this class
        return \DB::table('schedules')
            ->where('teacher_id', $teacher->id)
            ->where('class_id', $class->id)
            ->where('school_id', $teacher->school_id) // Extra security
            ->exists();
    }
}
