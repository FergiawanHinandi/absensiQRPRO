<?php

namespace App\Events;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * AttendanceCheckedIn Event
 *
 * Dispatched when a student successfully checks in (QR scan or manual).
 * Listener(s) can handle:
 * - Push notification to parents via FCM
 * - In-app notification to parent dashboard
 * - Real-time update to teacher's live attendance feed
 *
 * Usage:
 *   event(new AttendanceCheckedIn($attendance, $student));
 */
class AttendanceCheckedIn
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * The attendance record
     */
    public Attendance $attendance;

    /**
     * The student who checked in
     */
    public User $student;

    /**
     * Create a new event instance.
     */
    public function __construct(Attendance $attendance, User $student)
    {
        $this->attendance = $attendance;
        $this->student = $student;
    }
}
