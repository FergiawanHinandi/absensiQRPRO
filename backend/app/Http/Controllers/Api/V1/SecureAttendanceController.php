<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Http\Requests\Attendance\ScanAttendanceRequest;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * SecureAttendanceController - Example of a Fully Secured Controller
 * 
 * SECURITY PRINCIPLES IMPLEMENTED:
 * 1. Policy-based authorization via Gate::authorize()
 * 2. IDOR protection via school_id scoping
 * 3. Input validation via FormRequest
 * 4. Audit logging for sensitive operations
 * 5. Rate limiting at route level
 * 
 * This controller serves as a reference implementation for building
 * secure multi-tenant API endpoints.
 * 
 * @version 1.0.0
 */
class SecureAttendanceController extends Controller
{
    public function __construct(
        private AttendanceService $attendanceService
    ) {}

    /**
     * List attendance records (with proper authorization)
     * 
     * SECURITY:
     * - Gate::authorize() checks viewAny policy
     * - School scoping via BelongsToSchool trait (automatic)
     * - Role-based filtering for teachers (only their schedules)
     */
    public function index(Request $request): JsonResponse
    {
        // STEP 1: Authorization check
        Gate::authorize('viewAny', Attendance::class);
        
        $user = $request->user();
        
        // STEP 2: Build query with tenant isolation (automatic via global scope)
        $query = Attendance::query()
            ->with(['student:id,name,username', 'schedule:id,start_time,end_time'])
            ->orderBy('created_at', 'desc');
        
        // STEP 3: Role-based filtering to prevent IDOR
        if ($user->role_type === 'teacher') {
            // Teacher can only see attendance from their schedules
            $teacherScheduleIds = Schedule::where('teacher_id', $user->id)
                ->where('school_id', $user->school_id) // Extra safety
                ->pluck('id');
            
            $query->whereIn('schedule_id', $teacherScheduleIds);
        } elseif ($user->role_type === 'student') {
            // Student can only see their own attendance
            $query->where('student_id', $user->id);
        }
        // Admin roles see all attendance for their school (handled by global scope)
        
        // STEP 4: Apply filters from request
        if ($request->filled('date')) {
            $query->whereDate('attendance_date', $request->date);
        }
        
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        
        if ($request->filled('schedule_id')) {
            // IDOR Protection: Validate schedule belongs to user's school
            $scheduleId = $request->input('schedule_id');
            $schedule = Schedule::where('id', $scheduleId)
                ->where('school_id', $user->school_id)
                ->first();
            
            if (!$schedule) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jadwal tidak ditemukan.',
                ], 404);
            }
            
            $query->where('schedule_id', $scheduleId);
        }
        
        $attendances = $query->paginate($request->input('per_page', 20));
        
        return response()->json([
            'success' => true,
            'data' => $attendances,
        ]);
    }

    /**
     * View specific attendance record
     * 
     * SECURITY:
     * - Route model binding with school scoping
     * - Gate::authorize() checks view policy with model
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        // STEP 1: Find with tenant isolation (CRITICAL)
        // NEVER use Attendance::find($id) without school scoping!
        $attendance = Attendance::where('id', $id)
            ->where('school_id', $user->school_id)
            ->with(['student:id,name,username', 'schedule.subject', 'schedule.class'])
            ->first();
        
        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Data absensi tidak ditemukan.',
            ], 404);
        }
        
        // STEP 2: Authorization check with specific model
        Gate::authorize('view', $attendance);
        
        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => $attendance,
            ],
        ]);
    }

    /**
     * Create manual attendance entry
     * 
     * SECURITY:
     * - FormRequest validates input
     * - Gate::authorize() checks manualEntry policy with schedule
     * - IDOR protection for student_id and schedule_id
     * - Audit logging
     */
    public function store(ManualAttendanceRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();
        
        // STEP 1: IDOR Protection - Validate schedule belongs to user's school
        $schedule = Schedule::where('id', $validated['schedule_id'])
            ->where('school_id', $user->school_id) // CRITICAL: School check
            ->first();
        
        if (!$schedule) {
            Log::channel('security')->warning('idor_attempt', [
                'user_id' => $user->id,
                'user_school_id' => $user->school_id,
                'attempted_schedule_id' => $validated['schedule_id'],
                'action' => 'manual_attendance_create',
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan atau bukan milik sekolah Anda.',
            ], 404);
        }
        
        // STEP 2: Authorization check with schedule (checks ownership for teachers)
        Gate::authorize('manualEntry', [Attendance::class, $schedule]);
        
        // STEP 3: IDOR Protection - Validate student belongs to user's school
        $student = User::where('id', $validated['student_id'])
            ->where('school_id', $user->school_id) // CRITICAL: School check
            ->where('role_type', 'student')
            ->first();
        
        if (!$student) {
            Log::channel('security')->warning('idor_attempt', [
                'user_id' => $user->id,
                'user_school_id' => $user->school_id,
                'attempted_student_id' => $validated['student_id'],
                'action' => 'manual_attendance_create',
                'ip' => $request->ip(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Siswa tidak ditemukan atau bukan milik sekolah Anda.',
            ], 404);
        }
        
        // STEP 4: Create attendance via service (business logic)
        $attendance = $this->attendanceService->createManualAttendance([
            'school_id' => $user->school_id, // ALWAYS use user's school_id, never trust input
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'attendance_date' => $validated['attendance_date'],
            'status' => $validated['status'],
            'notes' => $validated['notes'] ?? null,
            'recorded_by' => $user->id,
            'is_manual' => true,
        ]);
        
        // STEP 5: Audit logging
        Log::channel('audit')->info('manual_attendance_created', [
            'user_id' => $user->id,
            'attendance_id' => $attendance->id,
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'school_id' => $user->school_id,
            'status' => $validated['status'],
            'ip' => $request->ip(),
        ]);
        
        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => $attendance->load(['student:id,name', 'schedule.subject']),
            ],
            'message' => 'Absensi manual berhasil disimpan.',
        ], 201);
    }

    /**
     * Update attendance record
     * 
     * SECURITY:
     * - Find with school scoping
     * - Gate::authorize() checks update policy
     * - Audit logging with before/after state
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        // STEP 1: Find with tenant isolation
        $attendance = Attendance::where('id', $id)
            ->where('school_id', $user->school_id)
            ->first();
        
        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Data absensi tidak ditemukan.',
            ], 404);
        }
        
        // STEP 2: Authorization check
        Gate::authorize('update', $attendance);
        
        // STEP 3: Validate input
        $validated = $request->validate([
            'status' => 'sometimes|in:present,late,sick,permit,alpha',
            'notes' => 'nullable|string|max:500',
        ]);
        
        // STEP 4: Store old state for audit
        $oldState = $attendance->only(['status', 'notes']);
        
        // STEP 5: Update
        $attendance->update($validated);
        
        // STEP 6: Audit logging
        Log::channel('audit')->info('attendance_updated', [
            'user_id' => $user->id,
            'attendance_id' => $attendance->id,
            'school_id' => $user->school_id,
            'old_state' => $oldState,
            'new_state' => $attendance->only(['status', 'notes']),
            'ip' => $request->ip(),
        ]);
        
        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => $attendance->fresh(['student:id,name', 'schedule.subject']),
            ],
            'message' => 'Data absensi berhasil diperbarui.',
        ]);
    }

    /**
     * Delete (soft delete) attendance record
     * 
     * SECURITY:
     * - Find with school scoping
     * - Gate::authorize() checks delete policy (admin only)
     * - Audit logging
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        
        // STEP 1: Find with tenant isolation
        $attendance = Attendance::where('id', $id)
            ->where('school_id', $user->school_id)
            ->first();
        
        if (!$attendance) {
            return response()->json([
                'success' => false,
                'message' => 'Data absensi tidak ditemukan.',
            ], 404);
        }
        
        // STEP 2: Authorization check (usually admin only)
        Gate::authorize('delete', $attendance);
        
        // STEP 3: Audit logging BEFORE deletion
        Log::channel('audit')->info('attendance_deleted', [
            'user_id' => $user->id,
            'attendance_id' => $attendance->id,
            'student_id' => $attendance->student_id,
            'schedule_id' => $attendance->schedule_id,
            'school_id' => $user->school_id,
            'attendance_date' => $attendance->attendance_date,
            'status' => $attendance->status,
            'ip' => $request->ip(),
        ]);
        
        // STEP 4: Soft delete
        $attendance->delete();
        
        return response()->json([
            'success' => true,
            'message' => 'Data absensi berhasil dihapus.',
        ]);
    }

    /**
     * Get attendance by schedule (Teacher view)
     * 
     * SECURITY:
     * - Schedule lookup with school scoping
     * - Gate::authorize() checks viewBySchedule policy
     */
    public function bySchedule(Request $request, int $scheduleId): JsonResponse
    {
        $user = $request->user();
        
        // STEP 1: Find schedule with tenant isolation
        $schedule = Schedule::where('id', $scheduleId)
            ->where('school_id', $user->school_id)
            ->with(['class.students', 'subject', 'teacher:id,name'])
            ->first();
        
        if (!$schedule) {
            return response()->json([
                'success' => false,
                'message' => 'Jadwal tidak ditemukan.',
            ], 404);
        }
        
        // STEP 2: Authorization check (teachers can only access their schedules)
        Gate::authorize('viewBySchedule', [Attendance::class, $schedule]);
        
        // STEP 3: Get attendance for today (or specified date)
        $date = $request->input('date', now()->toDateString());
        
        $attendances = Attendance::where('schedule_id', $scheduleId)
            ->where('school_id', $user->school_id)
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('student_id');
        
        // STEP 4: Merge with class students
        $students = $schedule->class->students->map(function ($student) use ($attendances) {
            $attendance = $attendances->get($student->id);
            
            return [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'status' => $attendance?->status ?? 'alpha',
                'check_in_time' => $attendance?->check_in_time,
                'is_manual' => $attendance?->is_manual ?? false,
                'attendance_id' => $attendance?->id,
            ];
        });
        
        return response()->json([
            'success' => true,
            'data' => [
                'schedule' => [
                    'id' => $schedule->id,
                    'subject' => $schedule->subject->name,
                    'class' => $schedule->class->name,
                    'teacher' => $schedule->teacher->name,
                    'start_time' => $schedule->start_time,
                    'end_time' => $schedule->end_time,
                    'date' => $date,
                ],
                'students' => $students,
                'summary' => [
                    'total' => $students->count(),
                    'present' => $students->where('status', 'present')->count(),
                    'late' => $students->where('status', 'late')->count(),
                    'sick' => $students->where('status', 'sick')->count(),
                    'permit' => $students->where('status', 'permit')->count(),
                    'alpha' => $students->where('status', 'alpha')->count(),
                ],
            ],
        ]);
    }
}
