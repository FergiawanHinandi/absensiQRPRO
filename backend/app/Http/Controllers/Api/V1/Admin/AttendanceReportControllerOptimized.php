<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ExportAttendanceReport;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use App\Services\AttendanceArchiveService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * OPTIMIZED Attendance Report Controller
 * 
 * OPTIMIZATIONS APPLIED:
 * 1. ✅ Eager loading with(['student', 'class'])
 * 2. ✅ Pagination default 20
 * 3. ✅ Dashboard caching with Cache::remember()
 * 4. ✅ Async job for Excel export
 * 5. ✅ Avoid select * - specify columns
 * 
 * @version 2.0.0 - Optimized
 */
class AttendanceReportControllerOptimized extends Controller
{
    protected $archiveService;

    public function __construct(AttendanceArchiveService $archiveService)
    {
        $this->archiveService = $archiveService;
    }

    /**
     * 1. Daily Attendance Report (per class)
     * 
     * OPTIMIZATIONS:
     * - Eager loading: with(['student', 'class'])
     * - Pagination: 20 per page
     * - Specific columns only (no select *)
     * - Single aggregation query for stats
     */
    public function daily(Request $request)
    {
        $user = $request->user();
        $date = $request->input('date', Carbon::today()->toDateString());
        $classId = $request->input('class_id');
        $perPage = $request->input('per_page', 20);
        
        $class = Classroom::findOrFail($classId);
        $this->authorize('viewClassReport', $class);

        // ✅ OPTIMIZED: Eager loading + specific columns + pagination
        $attendances = Attendance::with([
                'student:id,name,username,email',
                'class:id,name,grade_level'
            ])
            ->where('class_id', $classId)
            ->whereDate('attendance_date', $date)
            ->select([
                'id',
                'student_id',
                'class_id',
                'status',
                'check_in_time',
                'attendance_date',
                'is_manual',
                'created_at'
            ])
            ->orderBy('check_in_time')
            ->paginate($perPage);

        // ✅ OPTIMIZED: Single aggregation query
        $stats = Attendance::where('class_id', $classId)
            ->whereDate('attendance_date', $date)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
                SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit
            ")
            ->first();

        $total = (int) ($stats->total ?? 0);
        $present = (int) ($stats->present ?? 0);
        $late = (int) ($stats->late ?? 0);
        $attendanceRate = $total > 0 ? round((($present + $late) / $total) * 100, 2) : 0;

        Log::channel('audit')->info('attendance_report_viewed', [
            'user_id' => $user->id,
            'type' => 'daily',
            'class_id' => $classId,
            'date' => $date,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'attendances' => $attendances,
                'stats' => [
                    'total' => $total,
                    'present' => $present,
                    'late' => $late,
                    'absent' => (int) ($stats->absent ?? 0),
                    'sick' => (int) ($stats->sick ?? 0),
                    'permit' => (int) ($stats->permit ?? 0),
                    'attendance_rate' => $attendanceRate,
                ],
            ],
        ]);
    }

    /**
     * 2. Monthly Attendance Summary (per class)
     * 
     * OPTIMIZATIONS:
     * - Caching with Cache::remember()
     * - Database-level aggregation
     * - Pagination for student list
     */
    public function monthly(Request $request)
    {
        $user = $request->user();
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);
        $classId = $request->input('class_id');
        $perPage = $request->input('per_page', 20);
        
        $class = Classroom::findOrFail($classId);
        $this->authorize('viewClassReport', $class);

        // ✅ OPTIMIZED: Caching dashboard data
        $cacheKey = "monthly_attendance_{$classId}_{$month}_{$year}_v3";
        $summary = Cache::remember($cacheKey, 3600, function () use ($classId, $month, $year) {
            // Get school days count
            $schoolDays = Attendance::where('class_id', $classId)
                ->whereMonth('attendance_date', $month)
                ->whereYear('attendance_date', $year)
                ->distinct('attendance_date')
                ->count('attendance_date');

            // ✅ OPTIMIZED: Single aggregation for class totals
            $classTotals = Attendance::where('class_id', $classId)
                ->whereMonth('attendance_date', $month)
                ->whereYear('attendance_date', $year)
                ->selectRaw("
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
                    SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit,
                    COUNT(*) as total_records
                ")
                ->first();

            $totalStudents = DB::table('class_students')
                ->where('class_id', $classId)
                ->where('status', 'active')
                ->count();

            $avgRate = $schoolDays > 0 && $totalStudents > 0
                ? round((((int) ($classTotals->present ?? 0) + (int) ($classTotals->late ?? 0))
                    / ($schoolDays * $totalStudents)) * 100, 2)
                : 0;

            return [
                'total_school_days' => $schoolDays,
                'avg_attendance_rate' => $avgRate,
                'total_present' => (int) ($classTotals->present ?? 0),
                'total_late' => (int) ($classTotals->late ?? 0),
                'total_absent' => (int) ($classTotals->absent ?? 0),
                'total_sick' => (int) ($classTotals->sick ?? 0),
                'total_permit' => (int) ($classTotals->permit ?? 0),
            ];
        });

        // ✅ OPTIMIZED: Paginated student breakdown with specific columns
        // SECURITY FIX: Use leftJoinSub with parameter bindings instead of raw variable interpolation
        $attendanceSub = DB::table('attendances')
            ->select(
                'student_id',
                DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present"),
                DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late"),
                DB::raw("SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent"),
                DB::raw("SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick"),
                DB::raw("SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit")
            )
            ->where('class_id', $classId)
            ->whereRaw('EXTRACT(MONTH FROM attendance_date) = ?', [$month])
            ->whereRaw('EXTRACT(YEAR FROM attendance_date) = ?', [$year])
            ->groupBy('student_id');

        $schoolDaysInt = (int) $summary['total_school_days'];
        $students = DB::table('class_students')
            ->join('users', 'class_students.student_id', '=', 'users.id')
            ->leftJoinSub($attendanceSub, 'att', function ($join) {
                $join->on('class_students.student_id', '=', 'att.student_id');
            })
            ->where('class_students.class_id', $classId)
            ->where('class_students.status', 'active')
            ->select([
                'users.id',
                'users.name',
                'users.username',
                DB::raw('COALESCE(att.present, 0) as present'),
                DB::raw('COALESCE(att.late, 0) as late'),
                DB::raw('COALESCE(att.absent, 0) as absent'),
                DB::raw('COALESCE(att.sick, 0) as sick'),
                DB::raw('COALESCE(att.permit, 0) as permit'),
                DB::raw($schoolDaysInt > 0
                    ? "ROUND(((COALESCE(att.present, 0) + COALESCE(att.late, 0)) / " . $schoolDaysInt . ") * 100, 2) as attendance_rate"
                    : "0 as attendance_rate")
            ])
            ->orderBy('users.name')
            ->paginate($perPage);

        Log::channel('audit')->info('attendance_report_viewed', [
            'user_id' => $user->id,
            'type' => 'monthly',
            'class_id' => $classId,
            'month' => $month,
            'year' => $year,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'students' => $students,
            ],
        ]);
    }

    /**
     * 3. Student Monthly Report
     * 
     * OPTIMIZATIONS:
     * - Eager loading for student data
     * - Specific columns only
     * - Single aggregation query
     */
    public function studentMonthly(Request $request, $studentId)
    {
        $user = $request->user();
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);
        
        // ✅ OPTIMIZED: Eager load student with specific columns
        $student = Student::select(['id', 'name', 'username', 'email', 'class_id'])
            ->with('class:id,name,grade_level')
            ->findOrFail($studentId);
            
        $this->authorize('viewStudentReport', $student);

        // ✅ OPTIMIZED: Single aggregation query
        $stats = Attendance::where('student_id', $studentId)
            ->whereMonth('attendance_date', $month)
            ->whereYear('attendance_date', $year)
            ->selectRaw("
                COUNT(DISTINCT attendance_date) as school_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
                SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit
            ")
            ->first();

        $schoolDays = (int) ($stats->school_days ?? 0);
        $present = (int) ($stats->present ?? 0);
        $late = (int) ($stats->late ?? 0);
        $attendanceRate = $schoolDays > 0 ? round((($present + $late) / $schoolDays) * 100, 2) : 0;

        // ✅ OPTIMIZED: Specific columns for calendar
        $calendar = Attendance::where('student_id', $studentId)
            ->whereMonth('attendance_date', $month)
            ->whereYear('attendance_date', $year)
            ->select(['attendance_date as date', 'status', 'check_in_time'])
            ->orderBy('attendance_date')
            ->get();

        Log::channel('audit')->info('attendance_report_viewed', [
            'user_id' => $user->id,
            'type' => 'student_monthly',
            'student_id' => $studentId,
            'month' => $month,
            'year' => $year,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'student' => $student,
                'stats' => [
                    'school_days' => $schoolDays,
                    'present' => $present,
                    'late' => $late,
                    'absent' => (int) ($stats->absent ?? 0),
                    'sick' => (int) ($stats->sick ?? 0),
                    'permit' => (int) ($stats->permit ?? 0),
                    'attendance_rate' => $attendanceRate,
                ],
                'calendar' => $calendar,
            ],
        ]);
    }

    /**
     * 4. School-wide Dashboard
     * 
     * OPTIMIZATIONS:
     * - Heavy caching (60 minutes)
     * - Single aggregation query
     * - Specific columns only
     */
    public function dashboard(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $date = $request->input('date', Carbon::today()->toDateString());

        // ✅ OPTIMIZED: Dashboard caching
        $cacheKey = "dashboard_{$schoolId}_{$date}";
        $data = Cache::remember($cacheKey, 3600, function () use ($schoolId, $date) {
            // ✅ OPTIMIZED: Single query for today's stats
            $todayStats = Attendance::where('school_id', $schoolId)
                ->whereDate('attendance_date', $date)
                ->selectRaw("
                    COUNT(DISTINCT student_id) as total_students,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    SUM(CASE WHEN status = 'sick' THEN 1 ELSE 0 END) as sick,
                    SUM(CASE WHEN status = 'permit' THEN 1 ELSE 0 END) as permit
                ")
                ->first();

            // ✅ OPTIMIZED: Per-class breakdown with specific columns
            // SECURITY FIX: Use leftJoinSub with parameter bindings
            $classSub = DB::table('attendances')
                ->select(
                    'class_id',
                    DB::raw("COUNT(*) as total"),
                    DB::raw("SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present"),
                    DB::raw("SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late")
                )
                ->where('school_id', $schoolId)
                ->whereDate('attendance_date', $date)
                ->groupBy('class_id');

            $classSummary = DB::table('classes')
                ->leftJoinSub($classSub, 'att', function ($join) {
                    $join->on('classes.id', '=', 'att.class_id');
                })
                ->where('classes.school_id', $schoolId)
                ->where('classes.is_active', true)
                ->select([
                    'classes.id',
                    'classes.name',
                    'classes.grade_level',
                    DB::raw('COALESCE(att.total, 0) as total'),
                    DB::raw('COALESCE(att.present, 0) as present'),
                    DB::raw('COALESCE(att.late, 0) as late'),
                    DB::raw('CASE
                        WHEN COALESCE(att.total, 0) > 0
                        THEN ROUND(((COALESCE(att.present, 0) + COALESCE(att.late, 0))::numeric / COALESCE(att.total, 0)) * 100, 2)
                        ELSE 0
                    END as attendance_rate')
                ])
                ->orderBy('classes.grade_level')
                ->orderBy('classes.name')
                ->get();

            $totalStudents = (int) ($todayStats->total_students ?? 0);
            $present = (int) ($todayStats->present ?? 0);
            $late = (int) ($todayStats->late ?? 0);
            $overallRate = $totalStudents > 0 ? round((($present + $late) / $totalStudents) * 100, 2) : 0;

            return [
                'today' => [
                    'date' => $date,
                    'total_students' => $totalStudents,
                    'present' => $present,
                    'late' => $late,
                    'absent' => (int) ($todayStats->absent ?? 0),
                    'sick' => (int) ($todayStats->sick ?? 0),
                    'permit' => (int) ($todayStats->permit ?? 0),
                    'attendance_rate' => $overallRate,
                ],
                'classes' => $classSummary,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * 5. Export Excel (Async Job)
     * 
     * OPTIMIZATION:
     * - ✅ Async job for large exports
     * - Returns job ID for tracking
     * - User gets notified when ready
     */
    public function exportExcel(Request $request)
    {
        $user = $request->user();
        $type = $request->input('type'); // 'daily', 'monthly', 'student', 'school'
        $params = $request->validated();

        // ✅ OPTIMIZED: Dispatch async job with tenant context
        $job = ExportAttendanceReport::dispatch($user->id, $user->school_id, $type, $params);

        Log::channel('audit')->info('attendance_export_requested', [
            'user_id' => $user->id,
            'school_id' => $user->school_id,
            'type' => $type,
            'job_id' => $job->id ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Export sedang diproses. Anda akan menerima notifikasi saat selesai.',
            'data' => [
                'job_id' => $job->id ?? null,
                'estimated_time' => '1-5 menit',
            ],
        ]);
    }

    /**
     * 6. Check Export Status
     */
    public function exportStatus(Request $request, $jobId)
    {
        // Implementation depends on your job tracking system
        // Could use database table or Redis to track job status
        
        return response()->json([
            'success' => true,
            'data' => [
                'status' => 'processing', // 'processing', 'completed', 'failed'
                'progress' => 75, // percentage
                'download_url' => null, // URL when completed
            ],
        ]);
    }

    /**
     * 7. Historical Report (with Archive Support)
     * 
     * Query across main + archive tables automatically
     * 
     * OPTIMIZATION:
     * - ✅ Automatic archive detection
     * - ✅ UNION query across tables
     * - ✅ Maintains optimal indexes
     */
    public function historical(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $classId = $request->input('class_id');
        $studentId = $request->input('student_id');
        $perPage = $request->input('per_page', 20);

        // Validate date range
        if (!$startDate || !$endDate) {
            return response()->json([
                'success' => false,
                'message' => 'start_date dan end_date wajib diisi',
            ], 400);
        }

        // ✅ OPTIMIZED: Use archive service for automatic table detection
        $filters = [
            'school_id' => $schoolId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];

        if ($classId) {
            $filters['class_id'] = $classId;
        }
        if ($studentId) {
            $filters['student_id'] = $studentId;
        }

        // Get statistics
        $stats = $this->archiveService->getStatistics($filters);

        // Get paginated records
        $attendances = $this->archiveService->getAttendances($filters);
        
        // Manual pagination
        $page = $request->input('page', 1);
        $offset = ($page - 1) * $perPage;
        $paginatedData = $attendances->slice($offset, $perPage)->values();
        $total = $attendances->count();

        Log::channel('audit')->info('historical_report_viewed', [
            'user_id' => $user->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'tables_queried' => $stats['queried_tables'],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'attendances' => [
                    'data' => $paginatedData,
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
                'stats' => $stats,
                'date_range' => [
                    'start' => $startDate,
                    'end' => $endDate,
                ],
            ],
        ]);
    }

    /**
     * 8. Get Available Archives
     * 
     * List all available archive tables
     */
    public function archives(Request $request)
    {
        $archives = $this->archiveService->getAvailableArchives();

        return response()->json([
            'success' => true,
            'data' => [
                'archives' => $archives,
                'current_year' => Carbon::now()->year,
                'main_table' => 'attendances',
            ],
        ]);
    }
}
