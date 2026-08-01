<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Helpers\TimezoneHelper;
use App\Http\Controllers\Controller;
use App\Models\Schedule;
use App\Services\QRCodeGeneratorService;
use Illuminate\Http\Request;

/**
 * QR Generator Controller
 * 
 * Generates secure QR codes for attendance sessions
 * 
 * @version 2.0.0 - Security Hardened
 */
class QRGeneratorController extends Controller
{
    public function __construct(
        protected QRCodeGeneratorService $qrService
    ) {}

    /**
     * Generate QR code for attendance session
     * 
     * POST /api/v1/teacher/attendance/generate-qr
     * 
     * Request Body:
     * {
     *   "schedule_id": 123,
     *   "expiry_seconds": 60  // Optional, default: 60
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function generate(Request $request)
    {
        $request->validate([
            'schedule_id' => 'required|integer|exists:schedules,id',
            'expiry_seconds' => 'nullable|integer|min:30|max:300', // 30s - 5min
        ]);

        $teacher = $request->user();
        $scheduleId = $request->input('schedule_id');
        $expirySeconds = $request->input('expiry_seconds', 60);

        // Validate schedule belongs to teacher's school
        $schedule = Schedule::with('school', 'subject', 'class')
            ->where('id', $scheduleId)
            ->where('school_id', $teacher->school_id)
            ->where('is_active', true)
            ->firstOrFail();

        // Optional: Validate teacher is assigned to this schedule
        if ($schedule->teacher_id !== $teacher->id) {
            // Check if user has permission to generate QR for other teachers
            if (!$teacher->hasRole(['school_admin', 'principal', 'vice_principal'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Anda tidak memiliki akses ke jadwal ini.',
                ], 403);
            }
        }

        // ✅ NEW: Validate day and time
        $canBypass = config('attendance.allow_admin_bypass', true) 
            && $teacher->hasRole(['school_admin', 'principal', 'vice_principal']);

        if (!$canBypass && config('attendance.strict_time_validation', true)) {
            $timeValidation = $this->validateScheduleTime($schedule);
            
            if (!$timeValidation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $timeValidation['message'],
                    'error' => $timeValidation['error'],
                ], 422);
            }
        }

        // Generate secure QR payload
        $qrPayload = $this->qrService->generateAttendanceQR(
            $scheduleId,
            $teacher->school_id,
            $expirySeconds
        );

        return response()->json([
            'success' => true,
            'message' => 'QR Code berhasil dibuat.',
            'data' => [
                'qr_payload' => $qrPayload,
                'qr_string' => json_encode($qrPayload), // For QR code libraries
                'expires_at' => $qrPayload['data']['expires_at'],
                'expiry_seconds' => $expirySeconds,
                'schedule' => [
                    'id' => $schedule->id,
                    'subject' => $schedule->subject->name ?? null,
                    'class' => $schedule->class->name ?? null,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                ],
            ],
        ]);
    }

    /**
     * Get active attendance session
     * 
     * GET /api/v1/teacher/attendance/active-session
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function activeSession(Request $request)
    {
        $teacher = $request->user();
        $school = $teacher->school;
        $now = TimezoneHelper::schoolNow($school);

        // Get current schedule for teacher
        $schedule = Schedule::with(['subject', 'class'])
            ->where('teacher_id', $teacher->id)
            ->where('school_id', $teacher->school_id)
            ->where('day_of_week', $now->dayOfWeek)
            ->where('is_active', true)
            ->whereTime('start_time', '<=', $now->format('H:i:s'))
            ->whereTime('end_time', '>=', $now->format('H:i:s'))
            ->first();

        if (!$schedule) {
            return response()->json([
                'success' => true,
                'message' => 'Tidak ada jadwal aktif saat ini.',
                'data' => [
                    'has_active_session' => false,
                    'schedule' => null,
                ],
            ]);
        }

        // Get attendance count for this session
        $attendanceCount = \App\Models\Attendance::where('schedule_id', $schedule->id)
            ->where('school_id', $teacher->school_id)
            ->whereDate('attendance_date', TimezoneHelper::today($school))
            ->count();

        $totalStudents = $schedule->class->students()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'has_active_session' => true,
                'schedule' => [
                    'id' => $schedule->id,
                    'subject' => $schedule->subject->name ?? null,
                    'class' => $schedule->class->name ?? null,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                ],
                'stats' => [
                    'total_students' => $totalStudents,
                    'attended' => $attendanceCount,
                    'remaining' => $totalStudents - $attendanceCount,
                    'attendance_rate' => $totalStudents > 0 
                        ? round(($attendanceCount / $totalStudents) * 100, 1) 
                        : 0,
                ],
            ],
        ]);
    }

    /**
     * Get real-time attendance updates for session
     * 
     * GET /api/v1/teacher/attendance/session/{scheduleId}/live
     * 
     * @param Request $request
     * @param int $scheduleId
     * @return \Illuminate\Http\JsonResponse
     */
    public function liveAttendance(Request $request, int $scheduleId)
    {
        $teacher = $request->user();
        $school = $teacher->school;
        $today = TimezoneHelper::today($school);

        // Validate schedule belongs to teacher's school
        $schedule = Schedule::where('id', $scheduleId)
            ->where('school_id', $teacher->school_id)
            ->firstOrFail();

        // Get recent attendances (last 5 minutes)
        $recentAttendances = \App\Models\Attendance::with('student:id,name,username')
            ->where('schedule_id', $scheduleId)
            ->where('school_id', $teacher->school_id)
            ->whereDate('attendance_date', $today)
            ->where('created_at', '>=', TimezoneHelper::schoolNow($school)->subMinutes(5))
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // Get all attendances for today
        $allAttendances = \App\Models\Attendance::where('schedule_id', $scheduleId)
            ->where('school_id', $teacher->school_id)
            ->whereDate('attendance_date', $today)
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as late
            ', ['present', 'late'])
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'recent_attendances' => $recentAttendances,
                'stats' => [
                    'total' => (int) ($allAttendances->total ?? 0),
                    'present' => (int) ($allAttendances->present ?? 0),
                    'late' => (int) ($allAttendances->late ?? 0),
                ],
                'timestamp' => TimezoneHelper::now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Validate if current time is within schedule time
     * 
     * Enterprise-level validation with timezone support
     * 
     * @param Schedule $schedule
     * @return array
     */
    protected function validateScheduleTime(Schedule $schedule): array
    {
        $school = $schedule->school;
        $now = TimezoneHelper::schoolNow($school);
        $tolerance = config('attendance.qr_time_tolerance', 15);

        // Validate day
        if ((int)$schedule->day_of_week !== $now->dayOfWeek) {
            return [
                'valid' => false,
                'message' => 'QR code hanya dapat dibuat pada hari jadwal mengajar.',
                'error' => [
                    'code' => 'INVALID_DAY',
                    'expected_day' => $this->getDayName($schedule->day_of_week),
                    'current_day' => $this->getDayName($now->dayOfWeek),
                ],
            ];
        }

        // Build full datetime with today's date using TimezoneHelper
        $start = TimezoneHelper::timeFromString($schedule->start_time, $school)
            ->subMinutes($tolerance);

        $end = TimezoneHelper::timeFromString($schedule->end_time, $school)
            ->addMinutes($tolerance);

        if (!TimezoneHelper::isBetween($now, $start, $end)) {
            return [
                'valid' => false,
                'message' => 'QR code hanya dapat dibuat dalam rentang waktu jadwal mengajar.',
                'error' => [
                    'code' => 'INVALID_TIME',
                    'schedule_time' => "{$schedule->start_time} - {$schedule->end_time}",
                    'current_time' => $now->format('H:i:s'),
                    'allowed_time_range' => "{$start->format('H:i:s')} - {$end->format('H:i:s')}",
                ],
            ];
        }

        return [
            'valid' => true,
        ];
    }

    /**
     * Get day name in Indonesian
     * 
     * @param int $dayOfWeek
     * @return string
     */
    protected function getDayName(int $dayOfWeek): string
    {
        $days = config('attendance.day_names', [
            0 => 'Minggu',
            1 => 'Senin',
            2 => 'Selasa',
            3 => 'Rabu',
            4 => 'Kamis',
            5 => 'Jumat',
            6 => 'Sabtu',
        ]);

        return $days[$dayOfWeek] ?? 'Unknown';
    }
}
