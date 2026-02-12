<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Services\AttendanceCheckInService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\BulkManualAttendanceRequest;
use App\Http\Requests\Attendance\ManualAttendanceRequest;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Schedule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class TeacherStudentAttendanceController extends Controller
{
    protected AttendanceCheckInService $checkInService;

    public function __construct(AttendanceCheckInService $checkInService)
    {
        $this->checkInService = $checkInService;
    }

    /**
     * Get today's sessions/schedules for the teacher
     *
     * GET /api/v1/teacher/attendance/today-sessions
     */
    public function todaySessions(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $today = now()->format('l'); // Day name (Monday, Tuesday, etc.)
        $todayDate = now()->toDateString();

        $sessions = Schedule::where(function ($q) use ($teacher) {
                $q->where('teacher_id', $teacher->id)
                  ->orWhereHas('class', function ($cq) use ($teacher) {
                      $cq->where('homeroom_teacher_id', $teacher->id);
                  });
            })
            ->where('day_of_week', $today)
            ->where('is_active', true)
            ->with(['subject:id,name', 'class:id,name,grade'])
            ->orderBy('start_time')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'date' => $todayDate,
                'day' => $today,
                'sessions' => $sessions->map(fn ($s) => [
                    'id' => $s->id,
                    'subject_name' => $s->subject?->name ?? 'N/A',
                    'class_name' => $s->class?->name ?? 'N/A',
                    'grade' => $s->class?->grade ?? '-',
                    'start_time' => Carbon::parse($s->start_time)->format('H:i'),
                    'end_time' => Carbon::parse($s->end_time)->format('H:i'),
                    'is_active' => $this->isSessionActive($s),
                ]),
            ],
        ]);
    }

    /**
     * Check if a session is currently active (within time window)
     */
    private function isSessionActive(Schedule $schedule): bool
    {
        $now = now();
        $start = Carbon::parse($schedule->start_time)->subMinutes(15);
        $end = Carbon::parse($schedule->end_time)->addMinutes(30);
        
        return $now->between($start, $end);
    }

    /**
     * Get students with attendance status for a schedule
     *
     * GET /api/v1/teacher/schedules/{id}/attendance
     */
    public function getScheduleAttendance(Request $request, int $scheduleId): JsonResponse
    {
        $teacher = $request->user();
        
        // Find schedule with authorization
        $schedule = Schedule::where('id', $scheduleId)
            ->where(function ($q) use ($teacher) {
                $q->where('teacher_id', $teacher->id)
                  ->orWhereHas('class', function ($cq) use ($teacher) {
                      $cq->where('homeroom_teacher_id', $teacher->id);
                  });
            })
            ->with(['subject:id,name', 'class:id,name,grade'])
            ->firstOrFail();

        // Get date from query param or use today
        $date = $request->input('date', now()->toDateString());
        
        // Get class students
        $class = ClassRoom::with(['students' => function ($q) {
            $q->where('is_active', true)
              ->select('users.id', 'users.name', 'users.username');
        }])->findOrFail($schedule->class_id);

        // Get existing attendance records for this schedule and date
        $existingAttendances = Attendance::where('schedule_id', $schedule->id)
            ->whereDate('attendance_date', $date)
            ->get()
            ->keyBy('student_id');

        // Map students with their attendance status
        $studentsWithAttendance = $class->students->map(function ($student) use ($existingAttendances) {
            $attendance = $existingAttendances->get($student->id);
            
            return [
                'id' => $student->id,
                'name' => $student->name,
                'username' => $student->username,
                'status' => $attendance?->status ?? 'alpha',
                'check_in_time' => $attendance?->check_in_time?->format('H:i') ?? null,
                'is_manual' => $attendance?->is_manual ?? false,
                'attendance_id' => $attendance?->id ?? null,
                'notes' => $attendance?->notes ?? null,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'schedule' => [
                    'id' => $schedule->id,
                    'subject' => $schedule->subject?->name ?? 'N/A',
                    'class' => $schedule->class?->name ?? 'N/A',
                    'teacher' => $teacher->name,
                    'start_time' => Carbon::parse($schedule->start_time)->format('H:i'),
                    'end_time' => Carbon::parse($schedule->end_time)->format('H:i'),
                    'date' => $date,
                ],
                'students' => $studentsWithAttendance,
                'total_students' => $studentsWithAttendance->count(),
            ],
        ]);
    }

    /**
     * Create single manual attendance entry
     *
     * POST /api/v1/teacher/attendance/manual
     */
    public function manual(ManualAttendanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $teacher = $request->user();

        // Verify schedule ownership
        $schedule = Schedule::where('id', $validated['schedule_id'])
            ->where(function ($q) use ($teacher) {
                $q->where('teacher_id', $teacher->id)
                  ->orWhereHas('class', function ($cq) use ($teacher) {
                      $cq->where('homeroom_teacher_id', $teacher->id);
                  });
            })
            ->firstOrFail();

        Gate::authorize('manualEntry', [Attendance::class, $schedule]);

        $attendance = $this->checkInService->manualCheckIn(
            [
                'school_id' => $teacher->school_id,
                'student_id' => $validated['student_id'],
                'schedule_id' => $validated['schedule_id'],
                'attendance_date' => $validated['attendance_date'],
                'status' => $validated['status'],
                'notes' => $validated['notes'] ?? null,
            ],
            $teacher->id
        );

        return response()->json([
            'success' => true,
            'data' => [
                'attendance' => [
                    'id' => $attendance->id,
                    'student_id' => $attendance->student_id,
                    'status' => $attendance->status,
                    'check_in_time' => $attendance->check_in_time?->format('H:i'),
                ],
            ],
            'message' => 'Absensi manual berhasil disimpan.',
        ], 201);
    }

    /**
     * Create bulk manual attendance entries
     *
     * POST /api/v1/teacher/attendance/manual/bulk
     */
    public function bulkManual(BulkManualAttendanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $teacher = $request->user();
        $scheduleId = $validated['schedule_id'];
        $attendanceDate = $validated['attendance_date'];

        // Verify schedule ownership
        $schedule = Schedule::where('id', $scheduleId)
            ->where(function ($q) use ($teacher) {
                $q->where('teacher_id', $teacher->id)
                  ->orWhereHas('class', function ($cq) use ($teacher) {
                      $cq->where('homeroom_teacher_id', $teacher->id);
                  });
            })
            ->firstOrFail();

        Gate::authorize('manualEntry', [Attendance::class, $schedule]);

        $savedCount = 0;
        $skippedCount = 0;
        $errors = [];

        DB::beginTransaction();
        try {
            foreach ($validated['attendances'] as $item) {
                // Check for existing attendance (duplicate prevention)
                $existing = Attendance::where('student_id', $item['student_id'])
                    ->where('schedule_id', $scheduleId)
                    ->whereDate('attendance_date', $attendanceDate)
                    ->first();

                if ($existing) {
                    // Update existing attendance if status changed
                    if ($existing->status !== $item['status']) {
                        $existing->update([
                            'status' => $item['status'],
                            'notes' => $item['notes'] ?? $existing->notes,
                            'recorded_by' => $teacher->id,
                            'is_manual' => true,
                        ]);
                        $savedCount++;
                    } else {
                        $skippedCount++;
                    }
                } else {
                    // Create new attendance
                    $this->checkInService->manualCheckIn(
                        [
                            'school_id' => $teacher->school_id,
                            'student_id' => $item['student_id'],
                            'schedule_id' => $scheduleId,
                            'attendance_date' => $attendanceDate,
                            'status' => $item['status'],
                            'notes' => $item['notes'] ?? null,
                        ],
                        $teacher->id
                    );
                    $savedCount++;
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'data' => [
                    'saved_count' => $savedCount,
                    'skipped_count' => $skippedCount,
                    'total_processed' => count($validated['attendances']),
                ],
                'message' => "Berhasil menyimpan {$savedCount} absensi" . 
                    ($skippedCount > 0 ? ", {$skippedCount} dilewati (sudah ada/tidak berubah)" : '') . '.',
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan absensi: ' . $e->getMessage(),
            ], 500);
        }
    }
}
