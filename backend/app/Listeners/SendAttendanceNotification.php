<?php

namespace App\Listeners;

use App\Events\AttendanceAbsent;
use App\Events\AttendanceLate;
use App\Events\AttendanceRecorded;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendAttendanceNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(object $event): void
    {
        try {
            $attendance = $event->attendance;
            $student = User::find($attendance->student_id);
            if (!$student) return;

            // Get Parent and Homeroom Teacher
            $parents = $this->getParents($student);
            $homeroomTeacher = $this->getHomeroomTeacher($student);

            $eventName = class_basename($event);
            $logDescription = "";

            if ($eventName === 'AttendanceRecorded') {
                // Present
                $time = $attendance->check_in_time ? \Carbon\Carbon::parse($attendance->check_in_time)->format('H:i') : 'Unknown';
                
                // Notify Student
                $this->createNotification($student, 'Kehadiran Tercatat', "Anak Anda telah hadir di sekolah pukul {$time}.", 'attendance');
                
                // Notify Parent
                foreach ($parents as $parent) {
                    $this->createNotification($parent, 'Kehadiran Anak', "Anak Anda telah hadir di sekolah pukul {$time}.", 'attendance');
                }
                $logDescription = "Sent presence notification for {$student->name}";

            } elseif ($eventName === 'AttendanceLate') {
                // Late
                $time = $attendance->check_in_time ? \Carbon\Carbon::parse($attendance->check_in_time)->format('H:i') : 'Unknown';

                // Notify Parent
                foreach ($parents as $parent) {
                    $this->createNotification($parent, 'Keterlambatan', "Anak Anda datang terlambat hari ini pukul {$time}.", 'attendance');
                }
                // Notify Homeroom
                if ($homeroomTeacher) {
                    $this->createNotification($homeroomTeacher, 'Siswa Terlambat', "Siswa {$student->name} terlambat hari ini.", 'attendance');
                }
                $logDescription = "Sent late notification for {$student->name}";

            } elseif ($eventName === 'AttendanceAbsent') {
                // Absent
                
                // Check if notification already sent today to prevent spam
                // We check one parent as a proxy (assuming if one got it, others did too, or just check per parent)
                // Better: check inside the loop.
                
                // Notify Parent
                foreach ($parents as $parent) {
                    $exists = Notification::where('user_id', $parent->id)
                        ->where('type', 'attendance')
                        ->where('title', 'Ketidakhadiran')
                        ->whereDate('created_at', today())
                        ->exists();

                    if (!$exists) {
                        $this->createNotification($parent, 'Ketidakhadiran', "Anak Anda tidak tercatat hadir hari ini.", 'attendance');
                    }
                }
                // Notify Homeroom
                if ($homeroomTeacher) {
                     $exists = Notification::where('user_id', $homeroomTeacher->id)
                        ->where('type', 'attendance')
                        ->where('title', 'Siswa Absen')
                        ->whereDate('created_at', today())
                        ->exists();

                    if (!$exists) {
                        $this->createNotification($homeroomTeacher, 'Siswa Absen', "Siswa {$student->name} tidak hadir hari ini.", 'attendance');
                    }
                }
                $logDescription = "Sent absent notification for {$student->name}";

                // Check 2 consecutive absences
                $consecutive = Attendance::where('student_id', $student->id)
                    ->where('status', 'absent')
                    ->orderBy('attendance_date', 'desc')
                    ->take(2)
                    ->count();
                
                if ($consecutive >= 2) {
                    // High Priority Alert
                    foreach ($parents as $parent) {
                         $this->createNotification($parent, 'PERINGATAN: Absen Berturut-turut', "Anak Anda telah absen 2 hari berturut-turut.", 'risk');
                    }
                     if ($homeroomTeacher) {
                        $this->createNotification($homeroomTeacher, 'PERINGATAN: Siswa Absen Berturut-turut', "Siswa {$student->name} telah absen 2 hari berturut-turut.", 'risk');
                    }
                    $logDescription .= " (High Priority Alert: Consecutive Absences)";
                }
            }

            // Log audit
            AuditLog::create([
                'school_id' => $student->school_id ?? 1, // Fallback if no school_id
                'user_id' => null, // System action
                'action' => 'notification_sent',
                'module' => 'notification',
                'severity' => 'info',
                'description' => $logDescription,
                'ip_address' => 'system',
                'user_agent' => 'system',
            ]);

        } catch (\Exception $e) {
            Log::error("Failed to send attendance notification: " . $e->getMessage());
            AuditLog::create([
                'school_id' => 1,
                'user_id' => null,
                'action' => 'notification_failed',
                'module' => 'notification',
                'severity' => 'error',
                'description' => "Failed to send notification: " . $e->getMessage(),
                'ip_address' => 'system',
                'user_agent' => 'system',
            ]);
        }
    }

    private function createNotification($user, $title, $message, $type)
    {
        Notification::create([
            'user_id' => $user->id,
            'title' => $title,
            'message' => $message,
            'type' => $type,
        ]);
        // Placeholder for Push/Email logic
    }

    private function getParents($student)
    {
        return $student->parents;
    }

    private function getHomeroomTeacher($student)
    {
        $classStudent = ClassStudent::where('student_id', $student->id)->latest()->first(); // simplified logic
        if ($classStudent) {
            $class = ClassModel::find($classStudent->class_id);
            if ($class && $class->homeroom_teacher_id) {
                return User::find($class->homeroom_teacher_id);
            }
        }
        return null;
    }
}
