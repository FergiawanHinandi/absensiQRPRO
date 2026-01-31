<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use App\Repositories\OptimizedAttendanceRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AttendanceReportController - Authorized Attendance Access
 *
 * AUTHORIZATION STRATEGY:
 * - Policy-based authorization via $this->authorize()
 * - Eager loading to prevent N+1 queries
 * - School isolation enforced at multiple levels
 *
 * CONTROLLER RESPONSIBILITIES:
 * 1. Validate request
 * 2. Authorize action
 * 3. Delegate to repository/service
 * 4. Return response
 */
class AttendanceReportController extends Controller
{
    public function __construct(
        private OptimizedAttendanceRepository $attendanceRepo
    ) {}

    /**
     * List attendance records (paginated)
     *
     * Policy: viewAny - Can user view attendance list?
     * Eager Loading: schedule, student (prevents N+1)
     */
    public function index(Request $request): JsonResponse
    {
        // STEP 1: Authorize the action
        $this->authorize('viewAny', Attendance::class);

        $user = $request->user();
        $perPage = min($request->input('per_page', 20), 100);

        // STEP 2: Build query with eager loading
        $query = Attendance::query()
            // SECURITY: Scope to user's school (defense in depth)
            ->where('school_id', $user->school_id)
            // OPTIMIZATION: Eager load with specific columns
            ->with([
                'student:id,name,username,class_id',
                'schedule:id,subject_id,class_id,teacher_id,start_time',
                'schedule.subject:id,name,code',
                'schedule.class:id,name,grade_level',
            ])
            // OPTIMIZATION: Select only needed columns
            ->select([
                'id',
                'student_id',
                'schedule_id',
                'attendance_date',
                'status',
                'check_in_time',
                'is_manual',
            ]);

        // Filter by date range
        if ($request->has('start_date')) {
            $query->where('attendance_date', '>=', $request->input('start_date'));
        }
        if ($request->has('end_date')) {
            $query->where('attendance_date', '<=', $request->input('end_date'));
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $attendances = $query->orderByDesc('attendance_date')->paginate($perPage);

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
     * Daily report for school
     *
     * Policy: viewReports - Can user view reports?
     */
    public function dailyReport(Request $request): JsonResponse
    {
        // Authorize report viewing
        $this->authorize('viewReports', Attendance::class);

        $user = $request->user();
        $date = $request->input('date', now()->toDateString());

        $report = $this->attendanceRepo->dailyReport(
            $user->school_id,
            \Carbon\Carbon::parse($date)
        );

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
     * Policy: update - Can user update THIS attendance?
     */
    public function update(Request $request, int $attendanceId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:present,late,absent,sick,permit,excused',
            'notes' => 'nullable|string|max:500',
        ]);

        // STEP 1: Load attendance
        $attendance = Attendance::findOrFail($attendanceId);

        // STEP 2: Authorize - checks school, is_manual, role
        $this->authorize('update', $attendance);

        // STEP 3: Update
        $attendance->update([
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? $attendance->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Absensi berhasil diupdate.',
            'data' => $attendance->fresh(),
        ]);
    }

    /**
     * Delete attendance
     *
     * Policy: delete - Only admins can delete (soft delete)
     */
    public function destroy(Request $request, int $attendanceId): JsonResponse
    {
        $attendance = Attendance::findOrFail($attendanceId);

        // Authorize deletion
        $this->authorize('delete', $attendance);

        $attendance->delete(); // Soft delete

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
