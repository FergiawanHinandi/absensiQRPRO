<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\IDORProtection;
use App\Http\Requests\Attendance\DailyReportRequest;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Requests\Attendance\ScanAttendanceRequest;
use App\Services\AttendanceService;
use App\Traits\UsesCacheTags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Attendance Controller (Refactored)
 * 
 * CLEAN ARCHITECTURE PRINCIPLES:
 * 1. Controller only handles HTTP layer
 * 2. Validation via FormRequest
 * 3. Business logic in Service layer
 * 4. All queries are school-scoped
 * 5. Transactions handled in Service
 * 6. No mass assignment
 * 
 * @version 3.0.0
 */
class AttendanceController extends Controller
{
    use IDORProtection, UsesCacheTags;

    public function __construct(
        private AttendanceService $attendanceService,
        private \App\Services\AttendanceCheckInService $checkInService
    ) {}

    /**
     * Scan QR Code for attendance (Student only)
     * 
     * Controller responsibilities:
     * 1. Validate request via FormRequest
     * 2. Authorize via Policy
     * 3. Call service
     * 4. Return JSON response
     * 
     * @param ScanAttendanceRequest $request
     * @return JsonResponse
     */
    public function scan(ScanAttendanceRequest $request): JsonResponse
    {
        // Authorization check via Policy
        Gate::authorize('create', \App\Models\Attendance::class);

        try {
            // Get authenticated student
            $student = $request->user();

            // Get validated data with defaults
            $data = $request->validatedWithDefaults();

            // Log security context if present
            if (!empty($data['security_context'])) {
                $this->logSecurityContext($student, $data);
            }

            // Prepare scan data for service
            $scanData = [
                'qr_token' => $data['qr_token'],
                'lat' => $data['latitude'],
                'lng' => $data['longitude'],
                'accuracy' => $data['accuracy'],
                'altitude' => $data['altitude'],
                'speed' => $data['speed'],
                'heading' => $data['heading'],
                'is_mocked' => $data['is_mocked'],
                'device_id' => $data['device_id'] ?? null,
            ];

            // 5. Delegate to service (ALL business logic here)
            // Service will:
            // - Validate QR token
            // - Check nonce (replay prevention)
            // - Validate geofence (radius)
            // - Validate speed (anti-spoofing)
            // - Validate time window
            // - Determine status (present/late)
            // - Record attendance with SERVER timestamp
            $result = $this->checkInService->checkIn($student, $scanData, $request);

            // 6. Dispatch event (side effect after success)
            if ($result->isSuccessful() && $result->attendance) {
                $result->attendance->load(['student', 'schedule.class']);
                \App\Events\StudentAttended::dispatch($result->attendance, $student->school_id);

                \Illuminate\Support\Facades\Log::channel('audit')->info('attendance_scanned', [
                    'user_id' => $student->id,
                    'schedule_id' => $result->attendance->schedule_id,
                    'action' => 'scan_success',
                    'status' => $result->attendance->status, // SERVER-DETERMINED
                    'timestamp' => now(),
                ]);
            }

            // 7. Return response (formatting only)
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
     * Log security context from mobile client
     *
     * Logs security metadata for monitoring and analysis
     */
    private function logSecurityContext($student, array $data): void
    {
        $context = $data['security_context'] ?? [];
        
        // Only log if there are security concerns
        if (!empty($context['violations']) || $context['risk_level'] !== 'low') {
            Log::channel('attendance_security')->warning('Mobile security context', [
                'user_id' => $student->id,
                'is_secure' => $context['is_secure'] ?? true,
                'risk_level' => $context['risk_level'] ?? 'unknown',
                'violation_count' => $context['violation_count'] ?? 0,
                'violations' => $context['violations'] ?? [],
                'is_mocked' => $data['is_mocked'] ?? false,
                'device_fingerprint' => $data['device_fingerprint'] ?? null,
                'timestamp' => now()->toIso8601String(),
            ]);
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

        // SECURITY: Validate student belongs to authenticated user's school
        $student = $this->validateSchoolOwnershipById(
            \App\Models\User::class,
            $validated['student_id'],
            'Siswa tidak ditemukan atau bukan milik sekolah Anda.'
        );

        // SECURITY: Validate schedule belongs to same school (single query — replaces previous double-query)
        $schedule = $this->validateSchoolOwnershipById(
            \App\Models\Schedule::class,
            $validated['schedule_id'],
            'Jadwal tidak ditemukan atau bukan milik sekolah Anda.'
        );

        // ZERO-TRUST: Policy Check using already-loaded schedule
        \Illuminate\Support\Facades\Gate::authorize('manualEntry', [\App\Models\Attendance::class, $schedule]);

        // BUSINESS LOGIC: Delegated to service layer
        $attendance = $this->checkInService->manualCheckIn(
            [
                'school_id'       => $request->user()->school_id,
                'student_id'      => $validated['student_id'],
                'schedule_id'     => $validated['schedule_id'],
                'attendance_date' => $validated['attendance_date'],
                'status'          => $validated['status'],
                'notes'           => $validated['notes'] ?? null,
            ],
            $request->user()->id
        );

        return response()->json([
            'success' => true,
            'data'    => [
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
        // OPTIMIZATION: Add field selection to eager loading
        $schedule = \App\Models\Schedule::with([
            'class.students:id,name,username',
            'subject:id,name',
            'teacher:id,name'
        ])->findOrFail($scheduleId);

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
        $attendances = \App\Models\Attendance::with([
            'student:id,name,username',
            'schedule.class:id,name',
            'schedule.subject:id,name',
            'schedule.teacher:id,name'
        ])
            ->where('schedule_id', $scheduleId)
            ->where('attendance_date', now()->timezone(auth()->user()->school->timezone ?? config('app.timezone'))->toDateString())
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
        $today = now()->timezone($request->user()->school->timezone ?? config('app.timezone'))->toDateString();

        if ($dateParam) {
            // Validate date format (YYYY-MM-DD)
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam)) {
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

        return response()->success($data);
    }
}
