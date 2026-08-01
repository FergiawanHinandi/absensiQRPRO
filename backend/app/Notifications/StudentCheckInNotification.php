<?php

namespace App\Notifications;

use App\Models\Attendance;
use App\Notifications\Channels\FcmChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Student Check-In Notification
 *
 * Sent to parents when their child successfully checks in at school.
 * Delivered via FCM (push notification) and Database (in-app notification).
 *
 * Usage:
 *   $student->parents->each(function ($parent) use ($attendance) {
 *       $parent->notify(new StudentCheckInNotification($attendance, $student));
 *   });
 */
class StudentCheckInNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The attendance record
     */
    protected Attendance $attendance;

    /**
     * The student who checked in
     */
    protected \App\Models\User $student;

    /**
     * Create a new notification instance.
     */
    public function __construct(Attendance $attendance, \App\Models\User $student)
    {
        $this->attendance = $attendance;
        $this->student = $student;
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        // Send via FCM push + database in-app notification
        return [FcmChannel::class, 'database'];
    }

    /**
     * Get the FCM representation of the notification.
     */
    public function toFcm(object $notifiable): array
    {
        $studentName = $this->student->name;
        $time = $this->attendance->check_in_time
            ? Carbon::parse($this->attendance->check_in_time)->format('H:i')
            : '-';
        $subjectName = $this->attendance->schedule?->subject?->name ?? 'Pelajaran';
        $status = $this->attendance->status;

        $title = $status === 'late'
            ? "{$studentName} Terlambat 😅"
            : "{$studentName} Hadir ✅";

        $body = $status === 'late'
            ? "{$studentName} terlambat {$subjectName} pukul {$time}. Segera hubungi sekolah untuk info lebih lanjut."
            : "{$studentName} telah hadir di {$subjectName} pukul {$time}. Tetap semangat belajar!";

        return [
            'title' => $title,
            'body' => $body,
            'data' => [
                'type' => 'student_check_in',
                'student_id' => (string) $this->student->id,
                'student_name' => $this->student->name,
                'attendance_id' => (string) $this->attendance->id,
                'status' => $this->attendance->status,
                'time' => $time,
                'subject' => $subjectName,
            ],
        ];
    }

    /**
     * Get the array representation of the notification (for database channel).
     */
    public function toArray(object $notifiable): array
    {
        $studentName = $this->student->name;
        $time = $this->attendance->check_in_time
            ? Carbon::parse($this->attendance->check_in_time)->format('H:i')
            : '-';
        $subjectName = $this->attendance->schedule?->subject?->name ?? 'Pelajaran';
        $status = $this->attendance->status;

        $title = $status === 'late'
            ? "{$studentName} Terlambat"
            : "{$studentName} Hadir";

        $message = $status === 'late'
            ? "{$studentName} terlambat {$subjectName} pukul {$time}."
            : "{$studentName} telah hadir di {$subjectName} pukul {$time}.";

        return [
            'type' => 'student_check_in',
            'title' => $title,
            'message' => $message,
            'student_id' => $this->student->id,
            'student_name' => $this->student->name,
            'attendance_id' => $this->attendance->id,
            'status' => $this->attendance->status,
            'time' => $time,
            'subject' => $subjectName,
            'attendance_date' => $this->attendance->attendance_date,
        ];
    }
}
