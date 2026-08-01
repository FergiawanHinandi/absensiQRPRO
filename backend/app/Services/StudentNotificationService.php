<?php

namespace App\Services;

use App\Events\AttendanceCheckedIn;
use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StudentNotificationService
{
    /**
     * Send Check-in Notification
     * Triggered when student successfully scans QR
     * Dispatches AttendanceCheckedIn event for FCM push to parents
     */
    public function sendCheckInNotification(Attendance $attendance, User $student)
    {
        // Dispatch event for push notifications to parents
        try {
            event(new AttendanceCheckedIn($attendance, $student));
        } catch (\Exception $e) {
            Log::error('Failed to dispatch AttendanceCheckedIn event', [
                'attendance_id' => $attendance->id,
                'student_id' => $student->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Send in-app notification to student
        $title = 'Check-in Berhasil';
        $body = 'Anda telah check-in untuk '.($attendance->schedule->subject->name ?? 'kelas').' pukul '.Carbon::parse($attendance->check_in_time)->format('H:i');

        $this->sendToUser($student, 'check_in', $title, $body, [
            'attendance_id' => $attendance->id,
            'status' => $attendance->status,
            'time' => $attendance->check_in_time,
        ]);

        // Check for rate drop after this event
        if ($attendance->status === 'late') {
            $this->sendLateNotification($attendance, $student);
            $this->checkAttendanceRate($student);
        }
    }

    /**
     * Send Late Notification
     * Triggered when student scans late
     */
    public function sendLateNotification(Attendance $attendance, User $student)
    {
        $minutesLate = Carbon::parse($attendance->schedule->start_time)->diffInMinutes(Carbon::parse($attendance->check_in_time));

        $title = 'Late Arrival Recorded';
        $body = 'You are marked late for '.($attendance->schedule->subject->name ?? 'class')." ({$minutesLate} min).";

        $this->sendToUser($student, 'late_arrival', $title, $body, [
            'attendance_id' => $attendance->id,
            'minutes_late' => $minutesLate,
        ]);
    }

    /**
     * Send Absent Notification
     * Triggered by cron or manual entry
     */
    public function sendAbsentNotification(Attendance $attendance, User $student)
    {
        $title = 'Marked Absent';
        $body = 'You are marked absent for '.($attendance->schedule->subject->name ?? 'class').'. Please contact your teacher if this is a mistake.';

        $this->sendToUser($student, 'absent_recorded', $title, $body, [
            'attendance_id' => $attendance->id,
            'date' => $attendance->attendance_date,
        ]);

        $this->checkAttendanceRate($student);
    }

    /**
     * Check Attendance Rate and Notify if Critical
     */
    public function checkAttendanceRate(User $student)
    {
        $startOfMonth = Carbon::now(\school_timezone())->startOfMonth();
        $today = Carbon::now(\school_timezone());

        $stats = Attendance::where('student_id', $student->id)
            ->whereBetween('attendance_date', [$startOfMonth, $today])
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('present', 'late') then 1 else 0 end) as present
            ")
            ->first();

        if ($stats->total > 0) {
            $rate = ($stats->present / $stats->total) * 100;

            if ($rate < 75) {
                $this->sendToStudent($student, 'attendance_alert', 'Critical Attendance Alert', 'Your attendance rate has dropped to '.round($rate, 1).'%. Please improve your attendance.', [
                    'rate' => $rate,
                    'threshold' => 75,
                ]);
            }
        }
    }

    /**
     * Internal Sender - Sends in-app notification to user
     * Uses database channel for in-app notification display.
     * Parent FCM push notifications are handled via AttendanceCheckedIn event listener.
     */
    private function sendToUser(User $user, string $type, string $title, string $body, array $data = [])
    {
        try {
            // Send in-app notification via database channel
            $user->notify(new \App\Notifications\InAppNotification($type, $title, $body, $data));
        } catch (\Exception $e) {
            Log::warning('Failed to send in-app notification', [
                'user_id' => $user->id,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }

        // Always log for audit trail
        Log::channel('single')->info("[Notification] To: {$user->id} | Type: {$type} | {$title} - {$body}", $data);
    }
}
