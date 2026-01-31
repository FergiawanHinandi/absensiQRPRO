<?php

namespace App\Jobs;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendAttendanceNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected Attendance $attendance
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Load relationships
        $this->attendance->load(['student.parents', 'school']);

        $student = $this->attendance->student;
        $parents = $student->parents;

        if ($parents->isEmpty()) {
            Log::info("No parents found for student: {$student->name} (ID: {$student->id})");

            return;
        }

        foreach ($parents as $parent) {
            $this->sendNotification($parent, $student);
        }
    }

    protected function sendNotification(User $parent, User $student)
    {
        // In a real app, you would use a notification channel (FCM, WhatsApp, Email)
        // For now, we simulate by logging or hypothetical API call

        $message = "Halo {$parent->name}, anak Anda {$student->name} telah melakukan absensi pada {$this->attendance->check_in_time->format('H:i')}. Status: {$this->attendance->status}.";

        // Example: Log notification (Simulation)
        Log::channel('attendance')->info("NOTIFICATION_SENT: To {$parent->email} - {$message}");

        // Future Integration:
        // Http::post('https://wa-gateway.com/send', ['phone' => $parent->phone, 'message' => $message]);
    }
}
