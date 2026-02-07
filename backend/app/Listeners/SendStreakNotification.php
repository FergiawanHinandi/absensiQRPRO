<?php

namespace App\Listeners;

use App\Events\StreakAchieved;
use App\Models\AuditLog;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendStreakNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public function handle(StreakAchieved $event): void
    {
        try {
            $student = $event->student;
            $streak = $event->streak;

            $message = "Selamat! Kamu telah mencapai streak kehadiran {$streak} hari.";
            $this->createNotification($student, 'Streak Alert!', $message, 'streak');

            AuditLog::create([
                'school_id' => $student->school_id ?? 1,
                'user_id' => null,
                'action' => 'notification_sent',
                'module' => 'notification',
                'severity' => 'info',
                'description' => "Sent streak notification for {$student->name} (Streak: {$streak})",
                'ip_address' => 'system',
                'user_agent' => 'system',
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send streak notification: '.$e->getMessage());
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
    }
}
