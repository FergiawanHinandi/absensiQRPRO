<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\AttendanceUpdateRequest;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use App\Repositories\OptimizedAttendanceRepository;
use App\Services\AttendanceOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AttendanceReportController - Authorized Attendance Access
 *
 * CLEAN ARCHITECTURE:
 * - Policy-based authorization via $this->authorize()
 * - Business logic delegated to AttendanceOperationService
 * - Eager loading to prevent N+1 queries
 *
 * CONTROLLER RESPONSIBILITIES:
 * 1. Validate request (FormRequest)
 * 2. Authorize action (Policy)
 * 3. Delegate to service
 * 4. Return response
 */
class AttendanceReportController extends Controller
{
    public function __construct(
        private OptimizedAttendanceRepository $attendanceRepo,
        private AttendanceOperationService $operationService
    ) {}

    /**
     * List attendance records (Optimized for > 5M rows)
     *
     * OPTIMIZATION STRATEGY:
     * 1. Specific Select: Reducse memory footprint
     * 2. Eager Loading: Prevents N+1 queries
     * 3. Composite Index: Uses idx_attendance_report for filtering
     * 4. Cursor Pagination: O(1) offset for deep pagination
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Attendance::class);
        $user = $request->user();
        $perPage = min($request->input('per_page', 20), 100);

        // OPTIMIZED QUERY
        $query = Attendance::query()
            ->select([
                'id',
                'student_id',
                'schedule_id',
                'attendance_date',
                'status',
                'check_in_time',
                'is_manual',
            ])
            ->where('school_id', $user->school_id)
            ->with([
                'student:id,name,username,class_id',
                'schedule:id,subject_id,class_id,start_time',
                'schedule.subject:id,name,code',
                'schedule.class:id,name,grade_level',
            ]);

        // FILTERS (Index-Aware)
        // Composite Index Strategy: school_id + status + attendance_date
        
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->has('start_date')) {
            $query->where('attendance_date', '>=', $request->input('start_date'));
        }
        
        if ($request->has('end_date')) {
            $query->where('attendance_date', '<=', $request->input('end_date'));
        }

        // PAGINATION
        // Use cursorPaginate for large datasets (avoids slow COUNT(*) and OFFSET)
        // Fallback to simplePaginate if page numbers are strictly required
        if ($request->boolean('use_cursor', true)) {
            $attendances = $query->orderBy('attendance_date', 'desc')
                                 ->orderBy('id', 'desc') // Deterministic tie-breaker
                                 ->cursorPaginate($perPage);
        } else {
            $attendances = $query->orderBy('attendance_date', 'desc')
                                 ->simplePaginate($perPage); 
        }

        return response()->json([
            'success' => true,
            'data' => $attendances,
        ]);
    }

    /**
     * Show single attendance record
     *
     * Policy: view - Can user view THIS specific attendance?
     */
    public function show(Request $request, int $attendanceId): JsonResponse
    {
        // STEP 1: Load attendance with eager loading
        $attendance = Attendance::with([
            'student:id,name,username,email',
            'student.profile:user_id,nisn,phone',
            'schedule:id,subject_id,class_id,teacher_id,start_time,end_time',
            'schedule.subject:id,name',
            'schedule.class:id,name',
            'schedule.teacher:id,name',
            'recorder:id,name', // Who recorded manual attendance
        ])->findOrFail($attendanceId);

        // STEP 2: Authorize - Policy checks school_id + role
        $this->authorize('view', $attendance);

        return response()->json([
            'success' => true,
            'data' => $attendance,
        ]);
    }

    /**
     * Get attendance for a specific schedule (class session)
     *
     * Policy: viewBySchedule - Can user view this schedule's attendance?
     */
    public function bySchedule(Request $request, int $scheduleId): JsonResponse
    {
        // STEP 1: Load schedule
        $schedule = Schedule::with(['class', 'subject'])->findOrFail($scheduleId);

        // STEP 2: Authorize using schedule-specific policy
        $this->authorize('viewBySchedule', [Attendance::class, $schedule]);

        // STEP 3: Get attendance data (already authorized)
        $date = $request->input('date', now()->toDateString());
        $attendances = $this->attendanceRepo->classAttendance($scheduleId, \Carbon\Carbon::parse($date));

        // STEP 4: Get all students in class for complete roster
        $classStudents = User::where('role_type', 'student')
            ->whereHas('classStudents', fn ($q) => $q->where('class_id', $schedule->class_id)->where('status', 'active'))
            ->select(['id', 'name', 'username'])
            ->get();

        // STEP 5: Merge attendance with roster
        $roster = $classStudents->map(function ($student) use ($attendances) {
            $attendance = $attendances->get($student->id);

            return [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'username' => $student->username,
                'status' => $attendance?->status ?? 'alpha',
                'check_in_time' => $attendance?->check_in_time?->format('H:i:s'),
                'is_manual' => $attendance?->is_manual ?? false,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'schedule' => [
                    'id' => $schedule->id,
                    'subject' => $schedule->subject->name,
                    'class' => $schedule->class->name,
                    'date' => $date,
                ],
                'roster' => $roster,
                'summary' => [
                    'total' => $classStudents->count(),
                    'present' => $roster->where('status', 'present')->count(),
                    'late' => $roster->where('status', 'late')->count(),
                    'absent' => $roster->whereIn('status', ['absent', 'sick', 'permit', 'alpha'])->count(),
                ],
            ],
        ]);
    }

    /**
     * Daily report for school (Cached)
     *
     * Policy: viewReports - Can user view reports?
     */
    public function dailyReport(Request $request): JsonResponse
    {
        // Authorize report viewing
        $this->authorize('viewReports', Attendance::class);

        $user = $request->user();
        $date = $request->input('date', now()->toDateString());
        
        // CACHE: Cache report for 60 seconds (High Traffic Optimization)
        // Key format: report_{school_id}_{date}
        $cacheKey = "report_{$user->school_id}_{$date}";

        $report = \Illuminate\Support\Facades\Cache::remember($cacheKey, 60, function() use ($user, $date) {
            // Use Repository for optimized aggregation (Index Scan Only)
            return $this->attendanceRepo->dailyReport(
                $user->school_id,
                \Carbon\Carbon::parse($date)
            );
        });

        return response()->json([
            'success' => true,
            'data' => $report,
        ]);
    }

    /**
     * Monthly summary report
     *
     * Policy: viewReports - Can user view reports?
     */
    public function monthlySummary(Request $request): JsonResponse
    {
        $this->authorize('viewReports', Attendance::class);

        $user = $request->user();
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        $summary = $this->attendanceRepo->monthlySummary($user->school_id, $year, $month);

        return response()->json([
            'success' => true,
            'data' => [
                'year' => $year,
                'month' => $month,
                'students' => $summary,
            ],
        ]);
    }

    /**
     * Student's own attendance history
     *
     * Policy: Implicit - user can only see their own data
     */
    public function myHistory(Request $request): JsonResponse
    {
        $user = $request->user();

        // Students only
        if ($user->role_type !== 'student') {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint ini hanya untuk siswa.',
            ], 403);
        }

        $history = $this->attendanceRepo->studentHistory(
            $user->id,
            $request->input('per_page', 20)
        );

        return response()->json([
            'success' => true,
            'data' => $history,
        ]);
    }

    /**
     * Update attendance (manual only)
     *
     * CLEAN: FormRequest → Service → Response
     * All business logic in AttendanceOperationService::update()
     */
    public function update(AttendanceUpdateRequest $request, int $attendanceId): JsonResponse
    {
        $attendance = Attendance::findOrFail($attendanceId);

        // Policy authorization
        $this->authorize('update', $attendance);

        // Delegate to service - all logic in service layer
        $result = $this->operationService->update(
            $attendance,
            $request->validated(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Absensi berhasil diupdate.',
            'data' => $result,
        ]);
    }

    /**
     * Delete attendance
     *
     * CLEAN: Service handles soft delete with audit logging
     */
    public function destroy(Request $request, int $attendanceId): JsonResponse
    {
        $attendance = Attendance::findOrFail($attendanceId);

        // Policy authorization
        $this->authorize('delete', $attendance);

        // Delegate to service
        $this->operationService->delete($attendance, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Absensi berhasil dihapus.',
        ]);
    }

    /**
     * Export attendance data
     *
     * Policy: export - Only admins/principals
     */
    public function export(Request $request): JsonResponse
    {
        $this->authorize('export', Attendance::class);

        // Export logic...
        $user = $request->user();
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $data = Attendance::where('school_id', $user->school_id)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->with(['student:id,name,username', 'schedule:id,subject_id', 'schedule.subject:id,name'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $data->count(),
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
        ]);
    }
}
