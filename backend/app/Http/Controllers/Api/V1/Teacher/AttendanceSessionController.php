<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\User;
use App\Models\TeachingSession;
use App\Models\Attendance;

class AttendanceSessionController extends Controller
{
    public function todaySessions(Request $request)
    {
        $teacher = $request->user();
        $today = Carbon::today()->toDateString();
        $sessions = TeachingSession::with(['class', 'subject'])
            ->where('teacher_id', $teacher->id)
            ->whereDate('date', $today)
            ->get()
            ->map(function($s) {
                return [
                    'session_id' => $s->id,
                    'class' => $s->class->name ?? null,
                    'subject' => $s->subject->name ?? null,
                    'start_time' => $s->start_time,
                    'end_time' => $s->end_time,
                    'attendance_status' => $s->attendance_status,
                ];
            });
        return response()->json(['success' => true, 'data' => $sessions]);
    }

    public function startAttendance(Request $request, $sessionId)
    {
        $teacher = $request->user();
        $session = TeachingSession::where('id', $sessionId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();
        $now = Carbon::now();
        if ($now->lt(Carbon::parse($session->start_time)) || $now->gt(Carbon::parse($session->end_time))) {
            return response()->json(['success' => false, 'message' => 'Not within session time window'], 422);
        }
        $expiresAt = $now->copy()->addSeconds(60);
        $payload = [
            'session_id' => $session->id,
            'teacher_id' => $teacher->id,
            'generated_at' => $now->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
        $signature = hash_hmac('sha256', json_encode($payload), config('app.key'));
        $qrPayload = array_merge($payload, ['signature' => $signature]);
        $session->attendance_status = 'ongoing';
        $session->session_token = $signature;
        $session->session_token_expires_at = $expiresAt;
        $session->save();
        Log::channel('audit')->info('attendance_session_started', [
            'teacher_id' => $teacher->id,
            'session_id' => $session->id,
            'timestamp' => $now,
        ]);
        Log::channel('audit')->info('qr_generated', [
            'teacher_id' => $teacher->id,
            'session_id' => $session->id,
            'expires_at' => $expiresAt,
            'timestamp' => $now,
        ]);
        return response()->json([
            'success' => true,
            'qr_payload' => $qrPayload,
            'expires_at' => $expiresAt,
        ]);
    }

    public function closeAttendance(Request $request, $sessionId)
    {
        $teacher = $request->user();
        $session = TeachingSession::where('id', $sessionId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();
        $session->attendance_status = 'closed';
        $session->save();
        Log::channel('audit')->info('attendance_session_closed', [
            'teacher_id' => $teacher->id,
            'session_id' => $session->id,
            'timestamp' => now(),
        ]);
        return response()->json(['success' => true]);
    }

    public function attendanceStatus(Request $request, $sessionId)
    {
        $teacher = $request->user();
        $session = TeachingSession::where('id', $sessionId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();
        $students = $session->class->students ?? collect();
        $attendance = Attendance::where('session_id', $session->id)->get();
        $present = $attendance->where('status', 'present')->count();
        $late = $attendance->where('status', 'late')->count();
        $absent = $attendance->where('status', 'absent')->count();
        $notCheckedIn = $students->count() - $present - $late - $absent;
        return response()->json([
            'success' => true,
            'data' => [
                'total_students' => $students->count(),
                'present_count' => $present,
                'late_count' => $late,
                'absent_count' => $absent,
                'not_yet_checked_in' => $notCheckedIn,
            ]
        ]);
    }

    public function manualAttendance(Request $request, $sessionId)
    {
        $teacher = $request->user();
        $session = TeachingSession::where('id', $sessionId)
            ->where('teacher_id', $teacher->id)
            ->firstOrFail();
        $request->validate([
            'student_id' => 'required|integer',
            'status' => 'required|in:present,late,absent',
            'reason' => 'required|string',
        ]);
        $studentId = $request->input('student_id');
        $status = $request->input('status');
        $reason = $request->input('reason');
        $attendance = Attendance::firstOrNew([
            'session_id' => $session->id,
            'student_id' => $studentId,
        ]);
        $attendance->status = $status;
        $attendance->marked_by = $teacher->id;
        $attendance->marked_reason = $reason;
        $attendance->save();
        Log::channel('audit')->info('manual_attendance_added', [
            'teacher_id' => $teacher->id,
            'session_id' => $session->id,
            'student_id' => $studentId,
            'status' => $status,
            'timestamp' => now(),
        ]);
        return response()->json(['success' => true]);
    }
}
