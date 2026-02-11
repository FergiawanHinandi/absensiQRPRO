<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\Attendance;
use Illuminate\Support\Facades\Request;

class AttendanceAuditObserver
{
    /**
     * Handle the Attendance "created" event.
     */
    public function created(Attendance $attendance): void
    {
        // Distinguish between Scan and Manual
        $action = $attendance->is_manual ? 'manual_create' : 'scan_attendance';
        
        // If created via Scan, usage of `recorded_by` is reliable.
        $userId = $attendance->recorded_by ?? auth()->id();

        ActivityLog::create([
            'user_id' => $userId,
            'action' => $action,
            'model_type' => Attendance::class,
            'model_id' => $attendance->id,
            'school_id' => $attendance->school_id,
            'ip_address' => Request::ip() ?? $attendance->ip_address_in, // Assuming model might have IP
            'user_agent' => Request::userAgent(),
            'payload' => [
                'status' => $attendance->status,
                'type' => $attendance->attendance_type,
                'source' => $attendance->source,
            ]
        ]);
    }

    /**
     * Handle the Attendance "updated" event.
     */
    public function updated(Attendance $attendance): void
    {
        // Only log meaningful changes
        if ($attendance->wasChanged(['status', 'check_in_time', 'check_out_time', 'notes'])) {
            ActivityLog::create([
                'user_id' => auth()->id(), // Usually admin doing edits
                'action' => 'manual_edit',
                'model_type' => Attendance::class,
                'model_id' => $attendance->id,
                'school_id' => $attendance->school_id,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'payload' => [
                    'original' => $attendance->getOriginal(),
                    'changes' => $attendance->getChanges(),
                ]
            ]);
        }
    }

    /**
     * Handle the Attendance "deleted" event.
     */
    public function deleted(Attendance $attendance): void
    {
        ActivityLog::create([
            'user_id' => auth()->id(),
            'action' => 'delete_attendance',
            'model_type' => Attendance::class,
            'model_id' => $attendance->id,
            'school_id' => $attendance->school_id,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'payload' => [
                'deleted_at' => now(),
                'record' => $attendance->toArray()
            ]
        ]);
    }
}
