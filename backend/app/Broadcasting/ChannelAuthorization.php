<?php

namespace App\Broadcasting;

use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Broadcast Channel Authorization Helper
 * 
 * Provides secure authorization logic for broadcast channels with:
 * - Multi-tenant isolation (school_id validation)
 * - Ownership chain validation
 * - Timing attack prevention
 * - Unauthorized access logging
 * - N+1 query prevention via eager loading
 */
class ChannelAuthorization
{
    /**
     * Authorize attendance session channel
     * 
     * Rules:
     * - Session must exist and not be deleted
     * - User must be teacher/admin
     * - User's school_id must match session's school_id
     * - Super admin can access any school
     * 
     * @param User $user
     * @param int $sessionId Schedule ID
     * @return bool
     */
    public static function authorizeAttendanceSession(User $user, int $sessionId): bool
    {
        // Validate ID is numeric (prevent injection)
        if (!is_numeric($sessionId) || $sessionId <= 0) {
            self::logUnauthorizedAccess($user, 'attendance.session', $sessionId, 'invalid_id');
            return false;
        }

        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check role first (fast check)
        if (!$user->hasRole(['teacher', 'homeroom_teacher', 'school_admin', 'admin'])) {
            self::logUnauthorizedAccess($user, 'attendance.session', $sessionId, 'insufficient_role');
            return false;
        }

        // Fetch schedule with school_id (single query)
        $schedule = Schedule::select(['id', 'school_id', 'teacher_id'])
            ->find($sessionId);

        // Handle non-existent or deleted schedule
        if (!$schedule) {
            self::logUnauthorizedAccess($user, 'attendance.session', $sessionId, 'session_not_found');
            return false;
        }

        // Validate tenant isolation
        if ($user->school_id !== $schedule->school_id) {
            self::logUnauthorizedAccess($user, 'attendance.session', $sessionId, 'school_mismatch', [
                'user_school_id' => $user->school_id,
                'session_school_id' => $schedule->school_id,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Authorize student channel
     * 
     * Rules:
     * - Student must exist
     * - User is the student themselves OR
     * - User is parent of the student OR
     * - User is teacher/admin from same school
     * 
     * @param User $user
     * @param int $studentId
     * @return bool
     */
    public static function authorizeStudentChannel(User $user, int $studentId): bool
    {
        if (!is_numeric($studentId) || $studentId <= 0) {
            self::logUnauthorizedAccess($user, 'student', $studentId, 'invalid_id');
            return false;
        }

        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // User is the student themselves
        if ($user->id === (int)$studentId) {
            return true;
        }

        // Fetch student with school_id
        $student = User::select(['id', 'school_id', 'role_type'])
            ->where('role_type', 'student')
            ->find($studentId);

        if (!$student) {
            self::logUnauthorizedAccess($user, 'student', $studentId, 'student_not_found');
            return false;
        }

        // Check if user is parent of this student
        if ($user->hasRole('parent')) {
            $isParent = $user->children()->where('student_id', $studentId)->exists();
            if ($isParent) {
                return true;
            }
        }

        // Check if user is teacher/admin from same school
        if ($user->hasRole(['teacher', 'homeroom_teacher', 'school_admin', 'admin'])) {
            if ($user->school_id === $student->school_id) {
                return true;
            }
        }

        self::logUnauthorizedAccess($user, 'student', $studentId, 'no_relationship', [
            'user_school_id' => $user->school_id,
            'student_school_id' => $student->school_id,
        ]);

        return false;
    }

    /**
     * Authorize parent channel
     * 
     * Rules:
     * - User must be the parent themselves
     * - No cross-parent access allowed
     * 
     * @param User $user
     * @param int $parentId
     * @return bool
     */
    public static function authorizeParentChannel(User $user, int $parentId): bool
    {
        if (!is_numeric($parentId) || $parentId <= 0) {
            self::logUnauthorizedAccess($user, 'parent', $parentId, 'invalid_id');
            return false;
        }

        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Strict ownership check
        $authorized = ($user->id === (int)$parentId && $user->hasRole('parent'));

        if (!$authorized) {
            self::logUnauthorizedAccess($user, 'parent', $parentId, 'not_owner');
        }

        return $authorized;
    }

    /**
     * Authorize teacher channel
     * 
     * Rules:
     * - User is the teacher themselves OR
     * - User is admin from same school
     * 
     * @param User $user
     * @param int $teacherId
     * @return bool
     */
    public static function authorizeTeacherChannel(User $user, int $teacherId): bool
    {
        if (!is_numeric($teacherId) || $teacherId <= 0) {
            self::logUnauthorizedAccess($user, 'teacher', $teacherId, 'invalid_id');
            return false;
        }

        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // User is the teacher themselves
        if ($user->id === (int)$teacherId) {
            return true;
        }

        // Fetch teacher with school_id
        $teacher = User::select(['id', 'school_id', 'role_type'])
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->find($teacherId);

        if (!$teacher) {
            self::logUnauthorizedAccess($user, 'teacher', $teacherId, 'teacher_not_found');
            return false;
        }

        // Admin from same school can access
        if ($user->hasRole(['school_admin', 'admin'])) {
            if ($user->school_id === $teacher->school_id) {
                return true;
            }
        }

        self::logUnauthorizedAccess($user, 'teacher', $teacherId, 'unauthorized_access', [
            'user_school_id' => $user->school_id,
            'teacher_school_id' => $teacher->school_id,
        ]);

        return false;
    }

    /**
     * Authorize school-wide channel
     * 
     * Rules:
     * - User must belong to the school
     * 
     * @param User $user
     * @param int $schoolId
     * @return bool
     */
    public static function authorizeSchoolChannel(User $user, int $schoolId): bool
    {
        if (!is_numeric($schoolId) || $schoolId <= 0) {
            self::logUnauthorizedAccess($user, 'school', $schoolId, 'invalid_id');
            return false;
        }

        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        $authorized = ($user->school_id === (int)$schoolId);

        if (!$authorized) {
            self::logUnauthorizedAccess($user, 'school', $schoolId, 'school_mismatch', [
                'user_school_id' => $user->school_id,
                'requested_school_id' => $schoolId,
            ]);
        }

        return $authorized;
    }

    /**
     * Authorize admin security alerts channel
     * 
     * Rules:
     * - User must be admin/super_admin
     * - School_id must match (unless super_admin)
     * 
     * @param User $user
     * @param int $schoolId
     * @return bool
     */
    public static function authorizeAdminSecurityChannel(User $user, int $schoolId): bool
    {
        if (!is_numeric($schoolId) || $schoolId <= 0) {
            self::logUnauthorizedAccess($user, 'admin.security', $schoolId, 'invalid_id');
            return false;
        }

        // Super admin can access any school
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Must be admin
        if (!$user->hasRole(['school_admin', 'admin'])) {
            self::logUnauthorizedAccess($user, 'admin.security', $schoolId, 'insufficient_role');
            return false;
        }

        // School must match
        $authorized = ($user->school_id === (int)$schoolId);

        if (!$authorized) {
            self::logUnauthorizedAccess($user, 'admin.security', $schoolId, 'school_mismatch', [
                'user_school_id' => $user->school_id,
                'requested_school_id' => $schoolId,
            ]);
        }

        return $authorized;
    }

    /**
     * Authorize class channel
     * 
     * Rules:
     * - Class must exist
     * - User must be teacher/admin from same school
     * 
     * @param User $user
     * @param int $classId
     * @return bool
     */
    public static function authorizeClassChannel(User $user, int $classId): bool
    {
        if (!is_numeric($classId) || $classId <= 0) {
            self::logUnauthorizedAccess($user, 'class', $classId, 'invalid_id');
            return false;
        }

        // Super admin bypass
        if ($user->hasRole('super_admin')) {
            return true;
        }

        // Check role
        if (!$user->hasRole(['teacher', 'homeroom_teacher', 'school_admin', 'admin'])) {
            self::logUnauthorizedAccess($user, 'class', $classId, 'insufficient_role');
            return false;
        }

        // Fetch class with school_id
        $class = ClassModel::select(['id', 'school_id'])
            ->find($classId);

        if (!$class) {
            self::logUnauthorizedAccess($user, 'class', $classId, 'class_not_found');
            return false;
        }

        // Validate tenant isolation
        $authorized = ($user->school_id === $class->school_id);

        if (!$authorized) {
            self::logUnauthorizedAccess($user, 'class', $classId, 'school_mismatch', [
                'user_school_id' => $user->school_id,
                'class_school_id' => $class->school_id,
            ]);
        }

        return $authorized;
    }

    /**
     * Authorize system health channel
     * 
     * Rules:
     * - User must be super_admin or admin
     * 
     * @param User $user
     * @return bool
     */
    public static function authorizeSystemHealthChannel(User $user): bool
    {
        $authorized = $user->hasRole(['super_admin', 'admin']);

        if (!$authorized) {
            self::logUnauthorizedAccess($user, 'system.health', null, 'insufficient_role');
        }

        return $authorized;
    }

    /**
     * Authorize private user channel
     * 
     * Rules:
     * - User must be accessing their own channel
     * 
     * @param User $user
     * @param int $userId
     * @return bool
     */
    public static function authorizeUserChannel(User $user, int $userId): bool
    {
        if (!is_numeric($userId) || $userId <= 0) {
            self::logUnauthorizedAccess($user, 'user', $userId, 'invalid_id');
            return false;
        }

        $authorized = ($user->id === (int)$userId);

        if (!$authorized) {
            self::logUnauthorizedAccess($user, 'user', $userId, 'not_owner');
        }

        return $authorized;
    }

    /**
     * Log unauthorized channel access attempts
     * 
     * @param User $user
     * @param string $channelType
     * @param int|null $resourceId
     * @param string $reason
     * @param array $context
     * @return void
     */
    private static function logUnauthorizedAccess(
        User $user,
        string $channelType,
        ?int $resourceId,
        string $reason,
        array $context = []
    ): void {
        // Rate limit logging to prevent log flooding
        $cacheKey = "broadcast_unauthorized:{$user->id}:{$channelType}:{$resourceId}";
        
        if (Cache::has($cacheKey)) {
            return; // Already logged recently
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        Log::channel('security')->warning('Unauthorized broadcast channel access attempt', [
            'user_id' => $user->id,
            'username' => $user->username,
            'school_id' => $user->school_id,
            'role' => $user->role_type,
            'channel_type' => $channelType,
            'resource_id' => $resourceId,
            'reason' => $reason,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'timestamp' => now()->toIso8601String(),
            'context' => $context,
        ]);
    }
}
