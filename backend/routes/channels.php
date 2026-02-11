<?php

use App\Broadcasting\ChannelAuthorization;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels (SECURITY HARDENED)
|--------------------------------------------------------------------------
|
| All broadcast channels now implement:
| - Multi-tenant isolation (school_id validation)
| - Ownership chain validation
| - Timing attack prevention
| - Unauthorized access logging
| - N+1 query prevention
|
| Authorization logic is centralized in App\Broadcasting\ChannelAuthorization
|
*/

/**
 * Attendance Session Channel
 * 
 * Real-time updates during QR code scanning sessions.
 * 
 * Authorization:
 * - Session must exist and belong to user's school
 * - User must be teacher/admin from same school
 * - Super admin can access any school
 * 
 * Security:
 * - Validates school_id to prevent cross-school access
 * - Logs unauthorized attempts
 */
Broadcast::channel('attendance.session.{sessionId}', function ($user, $sessionId) {
    return ChannelAuthorization::authorizeAttendanceSession($user, $sessionId);
});

/**
 * Student Channel
 * 
 * Personal notifications for students.
 * 
 * Authorization:
 * - Student themselves
 * - Parent of the student
 * - Teacher/admin from same school
 * 
 * Security:
 * - Validates parent-student relationship
 * - Validates school_id for teachers/admins
 */
Broadcast::channel('student.{studentId}', function ($user, $studentId) {
    return ChannelAuthorization::authorizeStudentChannel($user, $studentId);
});

/**
 * Parent Channel
 * 
 * Personal notifications for parents.
 * 
 * Authorization:
 * - Parent themselves only
 * - No cross-parent access
 * 
 * Security:
 * - Strict ownership validation
 * - Role verification
 */
Broadcast::channel('parent.{parentId}', function ($user, $parentId) {
    return ChannelAuthorization::authorizeParentChannel($user, $parentId);
});

/**
 * Teacher Channel
 * 
 * Real-time updates for teacher dashboard.
 * 
 * Authorization:
 * - Teacher themselves
 * - Admin from same school
 * 
 * Security:
 * - Validates school_id for admins
 * - Prevents cross-school teacher monitoring
 */
Broadcast::channel('teacher.{teacherId}', function ($user, $teacherId) {
    return ChannelAuthorization::authorizeTeacherChannel($user, $teacherId);
});

/**
 * School-wide Channel
 * 
 * Announcements and updates for entire school.
 * 
 * Authorization:
 * - User must belong to the school
 * 
 * Security:
 * - Strict school_id validation
 * - Prevents cross-school listening
 */
Broadcast::channel('school.{schoolId}', function ($user, $schoolId) {
    return ChannelAuthorization::authorizeSchoolChannel($user, $schoolId);
});

/**
 * Admin Security Alerts Channel
 * 
 * Security events and alerts for administrators.
 * 
 * Authorization:
 * - Admin from same school
 * - Super admin can access any school
 * 
 * Security:
 * - Role-based access control
 * - School_id validation
 */
Broadcast::channel('admin.security.{schoolId}', function ($user, $schoolId) {
    return ChannelAuthorization::authorizeAdminSecurityChannel($user, $schoolId);
});

/**
 * System Health Channel
 * 
 * System monitoring and health metrics.
 * 
 * Authorization:
 * - Super admin only
 * - Regular admin (limited access)
 * 
 * Security:
 * - High-privilege channel
 * - Strict role validation
 */
Broadcast::channel('system.health', function ($user) {
    return ChannelAuthorization::authorizeSystemHealthChannel($user);
});

/**
 * Class Channel
 * 
 * Updates for specific class (attendance, assignments, etc).
 * 
 * Authorization:
 * - Teacher/admin from same school
 * - Class must exist and belong to user's school
 * 
 * Security:
 * - Validates class existence
 * - Validates school_id
 */
Broadcast::channel('class.{classId}', function ($user, $classId) {
    return ChannelAuthorization::authorizeClassChannel($user, $classId);
});

/**
 * Private User Channel
 * 
 * Personal notifications for individual users.
 * 
 * Authorization:
 * - User themselves only
 * 
 * Security:
 * - Strict ownership validation
 * - No delegation allowed
 */
Broadcast::channel('user.{userId}', function ($user, $userId) {
    return ChannelAuthorization::authorizeUserChannel($user, $userId);
});
