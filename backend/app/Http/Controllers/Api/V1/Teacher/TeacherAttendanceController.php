<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Core\Services\Attendance\TeacherAttendanceService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TeacherAttendanceScanRequest;
use App\Models\TeacherAttendance;
use App\Models\TeacherDevice;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherAttendanceController extends Controller
{
    protected TeacherAttendanceService $attendanceService;

    public function __construct(TeacherAttendanceService $attendanceService)
    {
        $this->attendanceService = $attendanceService;
    }

    /**
     * Check-in via QR scan
     *
     * POST /api/v1/teacher/attendance/check-in
     */
    public function checkIn(TeacherAttendanceScanRequest $request): JsonResponse
    {
        try {
            $teacher = $request->user();
            $attendance = $this->attendanceService->processCheckIn($teacher, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Check-in berhasil!',
                'data' => [
                    'attendance' => [
                        'id' => $attendance->id,
                        'date' => $attendance->attendance_date->format('Y-m-d'),
                        'status' => $attendance->status,
                        'check_in_time' => $attendance->check_in_time->format('H:i:s'),
                        'distance' => round($attendance->distance_in, 2).'m',
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Check-out via QR scan
     *
     * POST /api/v1/teacher/attendance/check-out
     */
    public function checkOut(TeacherAttendanceScanRequest $request): JsonResponse
    {
        try {
            $teacher = $request->user();
            $attendance = $this->attendanceService->processCheckOut($teacher, $request->validated());

            return response()->json([
                'status' => 'success',
                'message' => 'Check-out berhasil!',
                'data' => [
                    'attendance' => [
                        'id' => $attendance->id,
                        'date' => $attendance->attendance_date->format('Y-m-d'),
                        'status' => $attendance->status,
                        'check_in_time' => $attendance->check_in_time?->format('H:i:s'),
                        'check_out_time' => $attendance->check_out_time->format('H:i:s'),
                        'work_duration' => $attendance->check_in_time
                            ? $attendance->check_in_time->diff($attendance->check_out_time)->format('%H:%I:%S')
                            : null,
                    ],
                ],
            ]);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get today's attendance status
     *
     * GET /api/v1/teacher/attendance/today
     */
    public function today(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $attendance = TeacherAttendance::getTodayForTeacher($teacher->id);

        if (! $attendance) {
            return response()->json([
                'status' => 'success',
                'message' => 'Belum ada absensi hari ini.',
                'data' => [
                    'has_checked_in' => false,
                    'has_checked_out' => false,
                    'attendance' => null,
                ],
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_checked_in' => $attendance->check_in_time !== null,
                'has_checked_out' => $attendance->check_out_time !== null,
                'attendance' => [
                    'id' => $attendance->id,
                    'date' => $attendance->attendance_date->format('Y-m-d'),
                    'status' => $attendance->status,
                    'check_in_time' => $attendance->check_in_time?->format('H:i:s'),
                    'check_out_time' => $attendance->check_out_time?->format('H:i:s'),
                    'distance_in' => $attendance->distance_in ? round($attendance->distance_in, 2).'m' : null,
                    'distance_out' => $attendance->distance_out ? round($attendance->distance_out, 2).'m' : null,
                ],
            ],
        ]);
    }

    /**
     * Get attendance history for the authenticated teacher
     *
     * GET /api/v1/teacher/attendance/history
     */
    public function history(Request $request): JsonResponse
    {
        $teacher = $request->user();

        $attendances = TeacherAttendance::where('teacher_id', $teacher->id)
            ->orderBy('attendance_date', 'desc')
            ->paginate(20);

        return response()->json([
            'status' => 'success',
            'data' => [
                'attendances' => $attendances->map(fn ($a) => [
                    'id' => $a->id,
                    'date' => $a->attendance_date->format('Y-m-d'),
                    'day' => $a->attendance_date->translatedFormat('l'),
                    'status' => $a->status,
                    'check_in_time' => $a->check_in_time?->format('H:i'),
                    'check_out_time' => $a->check_out_time?->format('H:i'),
                    'is_manual' => $a->is_manual,
                ]),
                'pagination' => [
                    'current_page' => $attendances->currentPage(),
                    'last_page' => $attendances->lastPage(),
                    'per_page' => $attendances->perPage(),
                    'total' => $attendances->total(),
                ],
            ],
        ]);
    }

    /**
     * Get attendance summary for a month
     *
     * GET /api/v1/teacher/attendance/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $teacher = $request->user();
        $month = $request->input('month', now()->format('Y-m'));

        $startDate = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        $summary = $this->attendanceService->getSummary(
            $teacher->id,
            $startDate->toDateString(),
            $endDate->toDateString()
        );

        return response()->json([
            'status' => 'success',
            'data' => [
                'month' => $month,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * Get list of teacher's registered devices
     *
     * GET /api/v1/teacher/attendance/devices
     */
    public function devices(Request $request): JsonResponse
    {
        $teacher = $request->user();

        $devices = TeacherDevice::where('teacher_id', $teacher->id)
            ->orderBy('is_approved', 'desc')
            ->orderBy('last_used_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'devices' => $devices->map(fn ($d) => [
                    'id' => $d->id,
                    'device_id' => $d->device_id,
                    'device_name' => $d->device_name,
                    'platform' => $d->platform,
                    'is_approved' => $d->is_approved,
                    'approved_at' => $d->approved_at?->format('Y-m-d H:i'),
                    'last_used_at' => $d->last_used_at?->format('Y-m-d H:i'),
                ]),
            ],
        ]);
    }

    /**
     * Remove a teacher's registered device
     *
     * DELETE /api/v1/teacher/attendance/devices/{id}
     */
    public function removeDevice(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();

        $device = TeacherDevice::where('id', $id)
            ->where('teacher_id', $teacher->id)
            ->first();

        if (! $device) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Perangkat tidak ditemukan.',
            ], 404);
        }

        $device->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Perangkat berhasil dihapus.',
        ]);
    }
}
