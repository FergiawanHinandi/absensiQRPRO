<?php

namespace App\Listeners;

use App\Events\StudentAttended;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendParentAttendanceNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(StudentAttended $event): void
    {
        $attendance = $event->attendance;
        
        // Ensure relationships are loaded
        $attendance->load(['student.parents', 'schedule.subject']);
        
        $student = $attendance->student;
        $parents = $student->parents;

        if ($parents->isEmpty()) {
            return;
        }

        $subjectName = $attendance->schedule->subject->name ?? 'Class';
        $time = \Carbon\Carbon::parse($attendance->created_at)->format('H:i');

        // Determine Notification Content based on Triggers
        $shouldNotify = false;
        $title = '';
        $body = '';
        $type = 'info';

        // Trigger 1 & 3: Check-in & Late
        if ($attendance->status === 'present') {
            $shouldNotify = true;
            $title = "Child Checked In";
            $body = "Ananda {$student->name} telah hadir tepat waktu untuk {$subjectName} pukul {$time}.";
            $type = 'check_in';
        } elseif ($attendance->status === 'late') {
            $shouldNotify = true;
            $title = "Late Arrival Alert";
            $body = "Perhatian: Ananda {$student->name} terlambat hadir untuk {$subjectName} (Check-in: {$time}).";
            $type = 'late';
        }
        
        // Trigger 2: Child marked absent (status manually set? or via cron?)
        // Assuming this event is fired even when marking absent
        elseif (in_array($attendance->status, ['absent', 'alpha'])) {
            $shouldNotify = true;
            $title = "Absence Detected";
            $body = "Ananda {$student->name} tercatat tidak hadir ({$attendance->status}) untuk {$subjectName}.";
            $type = 'absent';

            // Trigger 4: Absent 2 days consecutively
            // We only check this if today is absent.
            $yesterday = \Carbon\Carbon::parse($attendance->attendance_date)->subDay()->toDateString();
            
            $wasAbsentYesterday = DB::table('attendances')
                ->where('student_id', $student->id)
                ->where('attendance_date', $yesterday)
                ->whereIn('status', ['absent', 'alpha', 'sick', 'permit'])
                ->exists();

            if ($wasAbsentYesterday) {
                // Determine header for consecutive absence? 
                // Or send a SEPARATE notification? Or append to body?
                // Let's send a specialized stronger alert.
                $title = "Consecutive Absence Alert";
                $body = "PERINGATAN: Ananda {$student->name} telah tidak hadir selama 2 hari berturut-turut.";
                $type = 'consecutive_absence';
            }
        }

        if ($shouldNotify) {
            $payload = [
                'title' => $title,
                'body' => $body,
                'data' => [
                    'student_id' => $student->id,
                    'type' => $type,
                    'attendance_id' => $attendance->id,
                    'timestamp' => now()->toIso8601String()
                ]
            ];

            foreach ($parents as $parent) {
                // If using FCM:
                // Notification::send($parent, new FCMNotification($payload));
                
                // For now, log the trigger as requested
                Log::info("PUSH NOTIFICATION to Parent [{$parent->id}]:", $payload);

                // In a real app, you would integrate:
                // if ($parent->device_token) {
                //     FireBase::send($parent->device_token, $title, $body, $payload['data']);
                // }
            }
        }
    }
}
