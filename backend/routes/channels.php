<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Attendance Session Channel - untuk real-time updates saat QR scanning
Broadcast::channel('attendance.session.{sessionId}', function ($user, $sessionId) {
    // Hanya teacher yang membuat session atau admin yang bisa listen
    return $user->hasRole(['teacher', 'homeroom_teacher', 'school_admin', 'admin']);
});

// Student Attendance Channel - untuk notifikasi ke student
Broadcast::channel('student.{studentId}', function ($user, $studentId) {
    // Hanya student yang bersangkutan atau parent/teacher yang bisa listen
    return $user->id == $studentId ||
           $user->hasRole(['parent', 'teacher', 'homeroom_teacher', 'school_admin', 'admin']);
});

// Parent Notification Channel
Broadcast::channel('parent.{parentId}', function ($user, $parentId) {
    // Hanya parent yang bersangkutan
    return $user->id == $parentId && $user->hasRole('parent');
});

// Teacher Dashboard Channel - untuk real-time updates di dashboard guru
Broadcast::channel('teacher.{teacherId}', function ($user, $teacherId) {
    // Hanya teacher yang bersangkutan atau admin
    return $user->id == $teacherId || $user->hasRole(['school_admin', 'admin']);
});

// School-wide Channel - untuk pengumuman sekolah
Broadcast::channel('school.{schoolId}', function ($user, $schoolId) {
    // Semua user yang terkait dengan sekolah tersebut
    return $user->school_id == $schoolId;
});

// Admin Security Alerts Channel
Broadcast::channel('admin.security.{schoolId}', function ($user, $schoolId) {
    // Hanya admin sekolah yang bersangkutan
    return $user->hasRole(['school_admin', 'admin', 'super_admin']) &&
           ($user->school_id == $schoolId || $user->hasRole('super_admin'));
});

// System Health Channel - untuk monitoring sistem
Broadcast::channel('system.health', function ($user) {
    // Hanya super admin dan admin yang bisa monitor sistem
    return $user->hasRole(['super_admin', 'admin']);
});

// Class-specific Channel - untuk update per kelas
Broadcast::channel('class.{classId}', function ($user, $classId) {
    // Teacher, homeroom teacher, atau admin yang mengajar/mengelola kelas tersebut
    return $user->hasRole(['teacher', 'homeroom_teacher', 'school_admin', 'admin']);
});

// Private User Channel - untuk notifikasi personal
Broadcast::channel('user.{userId}', function ($user, $userId) {
    // Hanya user yang bersangkutan
    return (int) $user->id === (int) $userId;
});
