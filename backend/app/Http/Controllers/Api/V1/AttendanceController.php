<?php

namespace App\Http\Controllers\Api\V1;

use App\Core\Services\Attendance\AttendanceService;
use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceScanRequest;
use App\Http\Requests\ManualAttendanceRequest;
use App\Services\AttendanceCheckInService;
use App\Traits\ValidatesSchoolOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AttendanceController extends Controller
{
    use \App\Traits\UsesCacheTags, ValidatesSchoolOwnership;

    public function __construct(
        private AttendanceService $attendanceService,
        private AttendanceCheckInService $checkInService
    ) {}

    /**
     * Scan QR Code for attendance (Student only)
     *
     * CLEAN ARCHITECTURE:
     * Controller responsibilities:
     * 1. Validate request
     * 2. Call service
     * 3. Return JSON response
     *
     * All business logic is in AttendanceCheckInService
     */
    public function scan(AttendanceScanRequest $request): JsonResponse
    {
        try {
            // 1. Get authenticated user
            $student = $request->user();

            // 2. Prepare scan data (simple mapping only)
            $deviceInfo = $request->input('device_info', []);
            $scanData = [
                'qr_token' => $request->input('token'),
                'lat' => $request->input('latitude'),
                'lng' => $request->input('longitude'),
                'accuracy' => $request->input('accuracy'),
                'device_id' => $deviceInfo['device_id'] ?? null,
                'request_id' => $request->input('request_id')
                    ?? $request->header('X-Request-ID')
                    ?? (string) Str::uuid(),
            ];

            // 3. Delegate to service (ALL business logic here)
            $result = $this->checkInService->checkIn($student, $scanData);

            // 4. Dispatch event (side effect after success)
            if ($result->isSuccessful() && $result->attendance) {
                $result->attendance->load(['student', 'schedule.class']);
                \App\Events\StudentAttended::dispatch($result->attendance, $student->school_id);
            }

            // 5. Return response (formatting only)
            return response()->json($result->toArray(), $result->getHttpStatusCode());

        } catch (AttendanceException $e) {
            // Business logic exceptions - safe to show to user
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'data' => [],
            ], 400);

        } catch (\Exception $e) {
            // Unexpected errors - log and return generic message
            Log::error('Attendance scan failed', [
                'user_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses absensi.',
                'data' => [],
            ], 500);
        }
    }

    /**
     * Manual attendance input (Teacher/Admin only)
     *
     * Controller responsibilities:
     * 1. Validate request
     * 2. Validate ownership (security)
     * 3. Call service
     * 4. Return response
     */
    public function manual(ManualAttendanceRequest $request)
    {
        $validated = $request->validated();

        // SECURITY: Validate student and schedule belong to same school
        $student = $this->validateSchoolOwnershipById(
            \App\Models\User::class,
            $validated['student_id'],
            'Siswa tidak ditemukan atau bukan milik sekolah Anda.'
        );

        $schedule = $this->validateSchoolOwnershipById(
            \App\Models\Schedule::class,
            $validated['schedule_id'],
            'Jadwal tidak ditemukan atau bukan milik sekolah Anda.'
        );

        // BUSINESS LOGIC: Delegated to service layer
        $attendance = $this->attendanceService->manualAttendance(
            [
                'school_id' => $request->user()->school_id,
                'student_id' => $validated['student_id'],
                'schedule_id' => $validated['schedule_id'],
                'attendance_date' => $validated['attendance_date'],
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ],
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => $attendance,
            ],
            'message' => 'Absensi manual berhasil disimpan',
        ], 201);
    }

    /**
     * Get student attendance history
     */
    public function history(Request $request)
    {
        $attendances = $request->user()
            ->attendances()
            ->with('schedule.subject', 'schedule.class')
            ->orderBy('attendance_date', 'desc')
            ->limit(30)
            ->get();

        return response()->success([
            'attendances' => $attendances,
        ]);
    }

    /**
     * Get class attendance for specific schedule (Teacher only)
     */
    public function classAttendance($scheduleId)
    {
        $schedule = \App\Models\Schedule::with('class.students')->findOrFail($scheduleId);

        // SECURITY: Validate schedule belongs to same school
        $this->validateSchoolOwnership($schedule, 'Jadwal tidak ditemukan atau bukan milik sekolah Anda.');

        // Authorization check (ensure teacher owns this schedule)
        if ($schedule->teacher_id !== auth()->id()) {
            return response()->json([
                'success' => false,
                'data' => [],
                'message' => 'Unauthorized',
            ], 403);
        }

        // Get attendances for this schedule TODAY
        $attendances = \App\Models\Attendance::with(['student', 'schedule.class', 'schedule.subject', 'schedule.teacher'])
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', now()->toDateString())
            ->get()
            ->keyBy('student_id');

        // Merge students with their attendance status
        $data = $schedule->class->students->map(function ($student) use ($attendances) {
            $attendance = $attendances->get($student->id);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'status' => $attendance ? $attendance->status : 'alpha', // Default alpha/absent if no record
                'check_in_time' => $attendance ? $attendance->check_in_time : null,
                'is_manual' => $attendance ? $attendance->is_manual : false,
            ];
        });

        return response()->success([
            'schedule' => [
                'id' => $schedule->id,
                'subject' => $schedule->subject->name,
                'class' => $schedule->class->name,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ],
            'students' => $data,
        ]);
    }

    /**
     * Daily report (Admin only) - OPTIMIZED: Single aggregation query
     */
    public function dailyReport(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // SECURITY FIX: Validate date format before parsing to prevent exception
        $dateParam = $request->query('date');
        $today = now()->toDateString();
        
        if ($dateParam) {
            // Validate date format (YYYY-MM-DD)
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date format. Use YYYY-MM-DD.',
                ], 422);
            }
            try {
                $today = \Illuminate\Support\Carbon::parse($dateParam)->toDateString();
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid date provided.',
                ], 422);
            }
        }
        
        $key = "attendance_daily_report_{$schoolId}_{$today}";
        $data = $this->cacheWithTags(['dashboard', "school_{$schoolId}"], $key, 600, function () use ($schoolId, $today) {
            
            // CRITICAL: Single query with aggregation instead of 5 separate queries
            $attendanceStats = \App\Models\Attendance::selectRaw('
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as present_count,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as late_count,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as sick_count,
                COUNT(DISTINCT CASE WHEN status = ? THEN student_id END) as permit_count,
                COUNT(DISTINCT student_id) as total_attended
            ', ['present', 'late', 'sick', 'permit'])
            ->where('school_id', $schoolId)
            ->whereDate('attendance_date', $today)
            ->first();

            // CRITICAL: Single query for total students
            $totalStudents = \App\Models\User::where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->count();

            $totalAttended = $attendanceStats->total_attended ?? 0;
            $alpha = max(0, $totalStudents - $totalAttended);

            return [
                'total_students' => $totalStudents,
                'attendance_rate' => $totalStudents > 0 ? round(($totalAttended / $totalStudents) * 100, 1) : 0,
                'present' => $attendanceStats->present_count ?? 0,
                'late' => $attendanceStats->late_count ?? 0,
                'sick' => $attendanceStats->sick_count ?? 0,
                'permission' => $attendanceStats->permit_count ?? 0,
                'alpha' => $alpha,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
