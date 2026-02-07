<?php

namespace App\Observers;

use App\Models\Attendance;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Context;

class AttendanceObserver
{
    /**
     * Handle the Attendance "updating" event.
     * Detect overrides by teachers/admins.
     */
    public function updating(Attendance $attendance): void
    {
        if ($attendance->isDirty('status')) {
            $user = auth()->user();

            // Only log if authorized user is making change (not system/cli)
            if ($user && ($user->role_type === 'teacher' || $user->role_type === 'school_admin')) {
                AuditLog::create([
                    'user_id' => $user->id,
                    'school_id' => $attendance->school_id,
                    'action' => 'override_attendance',
                    'description' => "Attendance ID #{$attendance->id} (User #{$attendance->student_id}) status changed: {$attendance->getOriginal('status')} -> {$attendance->status}",
                    'ip_address' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                    'metadata' => [
                        'trace_id' => Context::get('trace_id'),
                        'student_id' => $attendance->student_id,
                        'reason' => request()->input('reason') ?? 'Manual override'
                    ]
                ]);
            }
        }
    }
}
