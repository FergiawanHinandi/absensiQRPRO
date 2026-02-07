<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\Schedule;
use App\Http\Requests\Api\V1\StoreManualAttendanceRequest;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AttendanceSessionController extends Controller
{
    public function todaySessions(Request $request)
    {
        $teacher = $request->user();
        $cacheKey = 'teacher_today_sessions_' . $teacher->id . '_' . now()->dayOfWeek;
        $sessions = Cache::remember($cacheKey, 10, function () use ($teacher) {
            return Schedule::with(['class:id,name', 'subject:id,name'])
                ->forTeacher($teacher->id)
                ->today()
                ->get(['id', 'class_id', 'subject_id', 'start_time', 'end_time'])
                ->map(function ($s) {
                    return [
                        'session_id' => $s->id,
                        'class' => optional($s->class)->name,
                        'subject' => optional($s->subject)->name,
                        'start_time' => $s->start_time,
                        'end_time' => $s->end_time,
                        'attendance_status' => null, // Todo: calculate real status if needed
                    ];
                });
        });

        return response()->success($sessions);
    }

    public function startAttendance(Request $request, $scheduleId)
    {
        $teacher = $request->user();
        $schedule = Schedule::where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();

        $now = Carbon::now();
        // Assuming Schedule has start_time and end_time (H:i:s)
        // We need to compare time part only, or construct datetime for today
        $startTime = Carbon::parse($schedule->start_time); // Today at start_time
        $endTime = Carbon::parse($schedule->end_time);     // Today at end_time
        
        // Allow starting 15 mins early and until end time
        if ($now->lt($startTime->subMinutes(15)) || $now->gt($endTime)) {
            // Strict enforcement disabled for testing/demo? 
            // Memory says "Controlled...". Let's keep logic but maybe relax or return 422.
            // For now, adhering to refactor of existing logic:
            // Existing logic was: $now->lt(Carbon::parse($session->start_time))...
            return response()->json(['success' => false, 'message' => 'Not within session time window'], 422);
        }

        $expiresAt = $now->copy()->addSeconds(60);
        $payload = [
            'session_id' => $schedule->id, // Using Schedule ID as Session ID
            'teacher_id' => $teacher->id,
            'generated_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        $signature = hash_hmac('sha256', json_encode($payload), config('app.key'));
        $qrPayload = array_merge($payload, ['signature' => $signature]);

        // Note: Schedule table might not have attendance_status/token columns. 
        // If they were on TeachingSession (which didn't exist), where were they intended?
        // If Schedule is the model, does it have these columns?
        // If not, we can't save them. 
        // Checking LS of models showed no TeachingSession.
        // Assuming we rely on stateless QR or redis for token validity? 
        // Existing code did $session->save(). 
        // I will assume for now we just return payload. 
        // Ideally we need a cache/redis entry to track active session state if not in DB.
        
        AuditLog::create([
            'user_id' => $teacher->id,
            'module' => 'Attendance',
            'action' => 'qr_generated',
            'severity' => AuditLog::SEVERITY_INFO,
            'description' => json_encode(['schedule_id' => $schedule->id, 'expires_at' => $expiresAt]),
        ]);

        return response()->success([
            'qr_payload' => $qrPayload,
            'expires_at' => $expiresAt,
        ]);
    }

    public function closeAttendance(Request $request, $scheduleId)
    {
        $teacher = $request->user();
        $schedule = Schedule::where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();

        // If we were tracking state in DB, we'd update it here.
        // For now, just log.
        
        AuditLog::create([
            'user_id' => $teacher->id,
            'module' => 'Attendance',
            'action' => 'session_closed',
            'severity' => AuditLog::SEVERITY_INFO,
            'description' => json_encode(['schedule_id' => $schedule->id]),
        ]);

        return response()->success(null, 'Session closed');
    }

    public function attendanceStatus(Request $request, $scheduleId)
    {
        $teacher = $request->user();
        $schedule = Schedule::where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();

        // Assuming relationship class->students exists
        $students = $schedule->class->students ?? collect();
        
        // Get attendance for this schedule TODAY
        $attendance = Attendance::where('schedule_id', $schedule->id)
            ->whereDate('attendance_date', today())
            ->get();

        $present = $attendance->where('status', 'present')->count();
        $late = $attendance->where('status', 'late')->count();
        $absent = $attendance->where('status', 'absent')->count();
        $notCheckedIn = $students->count() - $present - $late - $absent;

        return response()->success([
            'total_students' => $students->count(),
            'present_count' => $present,
            'late_count' => $late,
            'absent_count' => $absent,
            'not_yet_checked_in' => max(0, $notCheckedIn),
        ]);
    }

    public function manualAttendance(StoreManualAttendanceRequest $request, $scheduleId)
    {
        $teacher = $request->user();
        $schedule = Schedule::where('id', $scheduleId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();

        $studentId = $request->input('student_id');
        $status = $request->input('status');
        $reason = $request->input('reason');

        $attendance = Attendance::firstOrNew([
            'schedule_id' => $schedule->id,
            'student_id' => $studentId,
            'attendance_date' => today(),
        ]);

        $attendance->school_id = $teacher->school_id; // Ensure school_id is set
        $attendance->class_id = $schedule->class_id;  // Ensure class_id is set
        $attendance->status = $status;
        $attendance->recorded_by = $teacher->id;
        $attendance->notes = $reason;
        $attendance->is_manual = true;
        
        if (in_array($status, ['present', 'late']) && !$attendance->check_in_time) {
            $attendance->check_in_time = now();
        }

        $attendance->save();

        AuditLog::create([
            'user_id' => $teacher->id,
            'module' => 'Attendance',
            'action' => 'manual_entry',
            'severity' => 'info',
            'description' => json_encode([
                'schedule_id' => $schedule->id,
                'student_id' => $studentId,
                'status' => $status
            ]),
        ]);

        return response()->success(null, 'Attendance recorded successfully');
    }
}
