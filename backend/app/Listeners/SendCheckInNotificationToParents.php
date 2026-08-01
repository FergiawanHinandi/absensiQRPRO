<?php

namespace App\Listeners;

use App\Events\AttendanceCheckedIn;
use App\Events\ParentNotificationEvent;
use App\Notifications\StudentCheckInNotification;
use Illuminate\Support\Facades\Log;

/**
 * Send Check-In Notification to Parents
 *
 * Listens for AttendanceCheckedIn event and:
 * 1. Broadcasts real-time notification to parent's private channel via Echo
 * 2. Sends push notification to parents via FCM (if device_token exists)
 * 3. Stores in-app database notification (always)
 */
class SendCheckInNotificationToParents
{
    /**
     * Handle the event.
     */
    public function handle(AttendanceCheckedIn $event): void
    {
        $attendance = $event->attendance;
        $student = $event->student;

        try {
            // Get all parents linked to this student
            $parents = $student->parents;

            if ($parents->isEmpty()) {
                Log::info('No parents linked to student, skipping push notification', [
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'attendance_id' => $attendance->id,
                ]);
                return;
            }

            $status = $attendance->status;
            $subjectName = $attendance->schedule?->subject?->name ?? 'Pelajaran';
            $time = $attendance->check_in_time
                ? \Carbon\Carbon::parse($attendance->check_in_time)->format('H:i')
                : '-';

            // Determine notification content based on status
            [$type, $title, $message] = match ($status) {
                'present' => [
                    'check_in',
                    "{$student->name} Hadir ✅",
                    "{$student->name} telah hadir di {$subjectName} pukul {$time}. Tetap semangat belajar!",
                ],
                'late' => [
                    'late',
                    "{$student->name} Terlambat 😅",
                    "{$student->name} terlambat {$subjectName} pukul {$time}. Segera hubungi sekolah untuk info lebih lanjut.",
                ],
                default => [
                    'alert',
                    "Update Kehadiran {$student->name}",
                    "Status kehadiran {$student->name} untuk {$subjectName}: " . ucfirst($status),
                ],
            };

            $studentInfo = [
                'id' => $student->id,
                'name' => $student->name,
            ];

            $extraData = [
                'attendance_id' => $attendance->id,
                'status' => $status,
                'subject' => $subjectName,
                'time' => $time,
                'attendance_date' => $attendance->attendance_date,
            ];

            // Send notification to each parent
            foreach ($parents as $parent) {
                // 1. Broadcast real-time to parent channel via Echo
                try {
                    event(new ParentNotificationEvent(
                        $parent,
                        $studentInfo,
                        $type,
                        $title,
                        $message,
                        $extraData
                    ));
                } catch (\Exception $e) {
                    Log::warning('Failed to broadcast parent notification event', [
                        'parent_id' => $parent->id,
                        'student_id' => $student->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // 2. Send FCM push + database notification (handles both via StudentCheckInNotification)
                try {
                    $parent->notify(new StudentCheckInNotification($attendance, $student));

                    Log::info('Check-in notification sent to parent', [
                        'parent_id' => $parent->id,
                        'student_id' => $student->id,
                        'attendance_id' => $attendance->id,
                        'status' => $status,
                        'has_device_token' => ! empty($parent->device_token),
                    ]);
                } catch (\Exception $e) {
                    Log::error('Failed to send StudentCheckInNotification to parent', [
                        'parent_id' => $parent->id,
                        'student_id' => $student->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::error('Failed to send check-in notification to parents', [
                'student_id' => $student->id,
                'attendance_id' => $attendance->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
