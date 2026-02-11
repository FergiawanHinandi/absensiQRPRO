<?php

namespace App\Jobs;

use App\Events\AttendanceRecorded;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdateDailyAttendanceSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $event;

    // Retry settings for transient DB issues
    public $tries = 3;
    public $timeout = 30;

    public function __construct(AttendanceRecorded $event)
    {
        $this->event = $event;
    }

    public function handle(): void
    {
        $attendance = $this->event->attendance;
        $schoolId = $attendance->school_id;
        $date = $attendance->attendance_date; // Assumed YYYY-MM-DD
        $status = $attendance->status; // 'present', 'late', 'absent', 'permission', 'sick'

        // Map status to column
        $columnMap = [
            'present' => 'total_present',
            'late'    => 'total_late', // Late counts as present + late usually, or separate?
                                     // Let's assume separate counter for purely dashboard stats
            'absent'  => 'total_absent', // Alpha
            'alpha'   => 'total_absent', // Alias
            'permission' => 'total_permission',
            'sick'    => 'total_sick',
        ];

        // If status is present but late, we might want to increment both present and late counters
        // DB Schema allows us to be flexible. Let's increment based on exact status string for now.
        $targetColumn = $columnMap[$status] ?? null;

        if (!$targetColumn) {
            Log::warning("Unknown status '{$status}' for attendance summary update.");
            return;
        }

        // ATOMIC UPSERT (Thread-Safe & Race-Condition Proof)
        // Insert 1 if not exists, else increment existing record
        // Eliminates "read-modify-write" race conditions entirely.
        
        try {
            DB::statement("
                INSERT INTO daily_attendance_summaries (school_id, date, $targetColumn, created_at, updated_at)
                VALUES (?, ?, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                $targetColumn = $targetColumn + 1,
                updated_at = NOW()
            ", [$schoolId, $date]);

        } catch (\Exception $e) {
            // Log and retry
            Log::error("Failed to update daily summary: " . $e->getMessage(), [
                'school_id' => $schoolId,
                'date' => $date
            ]);
            
            throw $e; // Trigger retry logic
        }
    }
}
