<?php

namespace App\Jobs;

use App\Events\AttendanceRecorded;
use App\Models\DailyAttendanceSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateDailyAttendanceSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $event;

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
        $date = $attendance->attendance_date;
        $status = $attendance->status;

        // Map status to counter column
        $columnMap = [
            'present' => 'total_present',
            'late' => 'total_late',
            'absent' => 'total_absent',
            'alpha' => 'total_absent',
            'permission' => 'total_permission',
            'excused' => 'total_excused',
            'sick' => 'total_sick',
        ];

        $targetColumn = $columnMap[$status] ?? null;

        if (! $targetColumn) {
            Log::warning("Unknown status '{$status}' for attendance summary update.");

            return;
        }

        // Use Eloquent updateOrCreate — DB-agnostic, works on PostgreSQL & MySQL
        try {
            $summary = DailyAttendanceSummary::firstOrNew([
                'school_id' => $schoolId,
                'attendance_date' => $date,
                'class_id' => $attendance->class_id,
            ]);

            $summary->{$targetColumn} = ($summary->{$targetColumn} ?? 0) + 1;
            $summary->last_updated_at = now();
            $summary->save();

        } catch (\Exception $e) {
            Log::error('Failed to update daily summary: '.$e->getMessage(), [
                'school_id' => $schoolId,
                'date' => $date,
            ]);

            throw $e;
        }
    }
}
