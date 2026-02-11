<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Services\SecureAttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Secure Attendance Controller
 * 
 * Handles QR code scanning with comprehensive security
 * 
 * SECURITY LAYERS:
 * 1. auth:sanctum - Authentication
 * 2. role:student - Authorization
 * 3. throttle:scan - Rate limiting (30/min)
 * 4. qr.validate - QR signature validation
 * 
 * @version 2.0.0 - Security Hardened
 */
class SecureAttendanceController extends Controller
{
    public function __construct(
        protected SecureAttendanceService $attendanceService
    ) {}

    /**
     * Scan QR code for attendance
     * 
     * POST /api/v1/student/attendance/scan
     * 
     * Request Body:
     * {
     *   "qr_payload": {
     *     "data": {
     *       "schedule_id": 123,
     *       "school_id": 1,
     *       "expires_at": "2026-02-07T10:01:00+07:00",
     *       "idempotency_key": "550e8400-e29b-41d4-a716-446655440000"
     *     },
     *     "signature": "a3f5b8c9..."
     *   },
     *   "latitude": -6.123,
     *   "longitude": 106.456,
     *   "device_id": "abc123"
     * }
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function scan(Request $request)
    {
        try {
            $student = $request->user();

            // Get validated QR payload from middleware
            $qrPayload = $request->input('validated_qr_payload');

            // Get scan data
            $scanData = $request->only(['latitude', 'longitude', 'device_id']);

            // Validate scan data
            $request->validate([
                'latitude' => 'nullable|numeric|between:-90,90',
                'longitude' => 'nullable|numeric|between:-180,180',
                'device_id' => 'nullable|string|max:255',
            ]);

            // Process attendance scan
            $attendance = $this->attendanceService->scan($student, $qrPayload, $scanData);

            return response()->json([
                'success' => true,
                'message' => 'Absensi berhasil dicatat.',
                'data' => [
                    'attendance' => [
                        'id' => $attendance->id,
                        'status' => $attendance->status,
                        'check_in_time' => $attendance->check_in_time,
                        'attendance_date' => $attendance->attendance_date,
                        'schedule' => [
                            'id' => $attendance->schedule->id,
                            'subject' => $attendance->schedule->subject->name ?? null,
                            'class' => $attendance->schedule->class->name ?? null,
                            'start_time' => $attendance->schedule->start_time,
                            'end_time' => $attendance->schedule->end_time,
                        ],
                    ],
                ],
            ], 201);

        } catch (AttendanceException $e) {
            // Business logic errors (user-friendly messages)
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan.',
            ], 404);

        } catch (\Exception $e) {
            // Log unexpected errors
            Log::error('Attendance scan failed', [
                'user_id' => $request->user()?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses absensi. Silakan coba lagi.',
            ], 500);
        }
    }

    /**
     * Get student attendance history
     * 
     * GET /api/v1/student/attendance/history
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function history(Request $request)
    {
        $student = $request->user();
        $limit = $request->input('limit', 30);

        $attendances = \App\Models\Attendance::with(['schedule.subject', 'schedule.class'])
            ->where('student_id', $student->id)
            ->where('school_id', $student->school_id)
            ->orderBy('attendance_date', 'desc')
            ->orderBy('check_in_time', 'desc')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'attendances' => $attendances,
            ],
        ]);
    }

    /**
     * Get today's attendance summary
     * 
     * GET /api/v1/student/attendance/today
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function today(Request $request)
    {
        $student = $request->user();
        $today = now()->toDateString();

        $attendances = \App\Models\Attendance::with(['schedule.subject', 'schedule.class'])
            ->where('student_id', $student->id)
            ->where('school_id', $student->school_id)
            ->whereDate('attendance_date', $today)
            ->orderBy('check_in_time')
            ->get();

        // Get today's schedules
        $schedules = \App\Models\Schedule::with(['subject', 'class'])
            ->whereHas('class.students', function ($query) use ($student) {
                $query->where('users.id', $student->id);
            })
            ->where('school_id', $student->school_id)
            ->where('day_of_week', now()->dayOfWeek)
            ->where('is_active', true)
            ->orderBy('start_time')
            ->get();

        // Merge schedules with attendance status
        $summary = $schedules->map(function ($schedule) use ($attendances) {
            $attendance = $attendances->firstWhere('schedule_id', $schedule->id);

            return [
                'schedule_id' => $schedule->id,
                'subject' => $schedule->subject->name ?? null,
                'class' => $schedule->class->name ?? null,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'status' => $attendance ? $attendance->status : 'not_yet',
                'check_in_time' => $attendance ? $attendance->check_in_time : null,
                'attendance_id' => $attendance ? $attendance->id : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $today,
                'summary' => $summary,
                'stats' => [
                    'total_schedules' => $schedules->count(),
                    'attended' => $attendances->count(),
                    'remaining' => $schedules->count() - $attendances->count(),
                ],
            ],
        ]);
    }
}
