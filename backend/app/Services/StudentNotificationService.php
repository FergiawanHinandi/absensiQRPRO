<?php

namespace App\Services;

use App\Models\User;
use App\Models\Attendance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class StudentNotificationService
{
    /**
     * Send Check-in Notification
     * Triggered when student successfully scans QR
     */
    public function sendCheckInNotification(Attendance $attendance, User $student)
    {
        $title = "Check-in Successful";
        $body = "You have successfully checked in for " . ($attendance->schedule->subject->name ?? 'class') . " at " . Carbon::parse($attendance->check_in_time)->format('H:i');
        
        $this->sendTostudent($student, 'check_in', $title, $body, [
            'attendance_id' => $attendance->id,
            'status' => $attendance->status,
            'time' => $attendance->check_in_time
        ]);
        
        // Check for rate drop after this event (unlikely on success unless late counts against rate in a specific way, but usually absent does)
        // If status is LATE, we might want to check rate?
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
        
        $title = "Late Arrival Recorded";
        $body = "You are marked late for " . ($attendance->schedule->subject->name ?? 'class') . " ({$minutesLate} min).";
        
        $this->sendTostudent($student, 'late_arrival', $title, $body, [
            'attendance_id' => $attendance->id,
            'minutes_late' => $minutesLate
        ]);
    }

    /**
     * Send Absent Notification
     * Triggered by cron or manual entry
     */
    public function sendAbsentNotification(Attendance $attendance, User $student)
    {
        $title = "Marked Absent";
        $body = "You are marked absent for " . ($attendance->schedule->subject->name ?? 'class') . ". Please contact your teacher if this is a mistake.";
        
        $this->sendTostudent($student, 'absent_recorded', $title, $body, [
            'attendance_id' => $attendance->id,
            'date' => $attendance->attendance_date
        ]);

        $this->checkAttendanceRate($student);
    }

    /**
     * Check Attendance Rate and Notify if Critical
     */
    public function checkAttendanceRate(User $student)
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $today = Carbon::now();

        $stats = DB::table('attendances')
            ->where('student_id', $student->id)
            ->whereBetween('attendance_date', [$startOfMonth, $today])
            ->selectRaw("
                count(*) as total,
                sum(case when status in ('present', 'late') then 1 else 0 end) as present
            ")
            ->first();

        if ($stats->total > 0) {
            $rate = ($stats->present / $stats->total) * 100;
            
            if ($rate < 75) {
                $this->sendToStudent($student, 'attendance_alert', 'Critical Attendance Alert', "Your attendance rate has dropped to " . round($rate, 1) . "%. Please improve your attendance.", [
                    'rate' => $rate,
                    'threshold' => 75
                ]);
            }
        }
    }

    /**
     * Internal Sender (Mock or FCM wrapper)
     */
    private function sendToStudent(User $user, string $type, string $title, string $body, array $data = [])
    {
        // In a real app, this would use FCM or OneSignal
        Log::channel('single')->info("[Notification] To: {$user->id} | Type: {$type} | {$title} - {$body}", $data);
        
        // Example Payload Structure
        /*
        {
            "to": "device_token_abc123",
            "notification": {
                "title": "Check-in Successful",
                "body": "You have successfully checked in..."
            },
            "data": {
                "type": "check_in",
                "attendance_id": 105,
                "click_action": "FLUTTER_NOTIFICATION_CLICK"
            }
        }
        */
    }
}
