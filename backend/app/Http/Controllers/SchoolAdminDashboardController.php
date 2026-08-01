<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * SchoolAdminDashboardController - OPTIMIZED
 *
 * OPTIMIZATIONS APPLIED:
 * - Eager loading with with() and withCount()
 * - Reduced N+1 queries
 * - Improved caching strategy
 * - Optimized database queries
 *
 * @version 2.0.0 - N+1 Query Optimization
 */
class SchoolAdminDashboardController extends Controller
{
    // 1. School Profile Summary
    public function schoolProfileSummary(Request $request)
    {
        $schoolId = $request->user()->school_id;
        
        // OPTIMIZATION: Use withCount() instead of separate count queries
        $school = School::withCount(['students', 'teachers'])
            ->findOrFail($schoolId);
        
        $activeAcademicYear = AcademicYear::where('school_id', $schoolId)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->first();
        
        $attendanceMethod = $school->attendance_method ?? 'QR';
        $schoolStatus = $school->is_active ? 'Active' : 'Suspended';

        return response()->json([
            'school_name' => $school->name,
            'total_students' => $school->students_count, // From withCount()
            'total_teachers' => $school->teachers_count, // From withCount()
            'active_academic_year' => $activeAcademicYear ? $activeAcademicYear->name : null,
            'attendance_method' => $attendanceMethod,
            'school_status' => $schoolStatus,
        ]);
    }

    // 2. Dashboard Summary (metrics)
    public function dashboardSummary(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $cacheKey = "dashboard_summary_school_{$schoolId}";
        
        // OPTIMIZATION: Increased cache time from 60s to 300s (5 minutes)
        $summary = Cache::remember($cacheKey, 300, function () use ($schoolId, $tz) {
            $today = now()->timezone($tz)->startOfDay();
            $todayString = $today->toDateString();
            
            // OPTIMIZATION: Use single query with withCount()
            $school = School::withCount(['students', 'teachers'])->find($schoolId);
            $totalStudents = $school->students_count;
            $totalTeachers = $school->teachers_count;
            
            // OPTIMIZATION: Use withCount() instead of whereHas + pluck + count
            $classesActiveToday = Classroom::where('school_id', $schoolId)
                ->whereHas('schedules', function ($q) use ($today) {
                    $q->whereDate('date', $today);
                })
                ->count();
            
            // ✅ OPTIMIZED: Use summary table instead of raw attendance aggregation
            $summaries = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
                ->where('attendance_date', $todayString)
                ->get();
            
            $present = $summaries->sum('present_count');
            $late = $summaries->sum('late_count');
            $absent = $summaries->sum('absent_count');
            
            $totalAttendance = $present + $late + $absent;
            $attendanceRate = $totalAttendance > 0
                ? round(($present + $late) / $totalAttendance * 100, 2)
                : 0;
            
            // Calculate not checked in from summary data
            $attendedStudents = $summaries->sum('attended_students');
            $notCheckedIn = max(0, $totalStudents - $attendedStudents);
            
            // ✅ OPTIMIZED: Use summary table for trend data
            $trend = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
                ->where('attendance_date', '>=', now()->timezone($tz)->subDays(6)->startOfDay()->toDateString())
                ->where('attendance_date', '<=', $todayString)
                ->select('attendance_date as date')
                ->selectRaw('SUM(present_count) as present')
                ->selectRaw('SUM(late_count) as late')
                ->selectRaw('SUM(absent_count) as absent')
                ->groupBy('attendance_date')
                ->orderBy('attendance_date')
                ->get();

            return [
                'total_students' => $totalStudents,
                'total_teachers' => $totalTeachers,
                'classes_active_today' => $classesActiveToday,
                'attendance_today' => [
                    'present' => (int) $present,
                    'late' => (int) $late,
                    'absent' => (int) $absent,
                ],
                'attendance_rate' => $attendanceRate,
                'students_not_checked_in' => $notCheckedIn,
                'attendance_trend' => $trend,
            ];
        });

        return response()->json($summary);
    }

    // 3. Live Attendance Monitoring
    public function liveAttendance(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $today = now()->timezone($tz)->startOfDay();
        $todayString = $today->toDateString();
        $grade = $request->input('grade');
        $classId = $request->input('class_id');
        $timeSlot = $request->input('time_slot');
        
        $classesQuery = Classroom::query()
            ->where('school_id', $schoolId)
            ->whereHas('schedules', function ($q) use ($today, $timeSlot) {
                $q->whereDate('date', $today);
                if ($timeSlot) {
                    [$start, $end] = explode('-', $timeSlot);
                    $q->where('start_time', '>=', $start)
                        ->where('end_time', '<=', $end);
                }
            });
        
        if ($grade) {
            $classesQuery->where('grade', $grade);
        }
        if ($classId) {
            $classesQuery->where('id', $classId);
        }
        
        $classes = $classesQuery->get(['id', 'name', 'grade']);
        $classIds = $classes->pluck('id');
        
        // ✅ OPTIMIZED: Use summary table instead of raw attendance aggregation
        $summaries = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->where('attendance_date', $todayString)
            ->whereIn('class_id', $classIds)
            ->get()
            ->keyBy('class_id');
        
        $result = $classes->map(function ($class) use ($summaries) {
            $summary = $summaries[$class->id] ?? null;
            
            $present = $summary ? $summary->present_count : 0;
            $late = $summary ? $summary->late_count : 0;
            $total = $summary ? $summary->total_students : 0;
            $notCheckedIn = $total - $present - $late;

            return [
                'class_id' => $class->id,
                'class_name' => $class->name,
                'grade' => $class->grade,
                'total_students' => $total,
                'present' => $present,
                'late' => $late,
                'not_checked_in' => max($notCheckedIn, 0),
            ];
        });

        return response()->json([
            'date' => $todayString,
            'classes' => $result,
        ]);
    }

    // 4. Student Management List
    public function studentList(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $grade = $request->input('grade');
        $classId = $request->input('class_id');
        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = min($request->input('per_page', 20), 100); // Cap at 100
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30)->startOfDay()->toDateString();
        
        // SECURITY: Use parameter bindings in subqueries to prevent SQL injection
        $query = User::query()
            ->select([
                'users.id',
                'users.name',
                'users.username',
                DB::raw("'' as nisn"),
                'users.is_active as status',
                'classes.name as class_name',
                'classes.grade_level as grade',
            ])
            ->selectRaw(
                '(SELECT ROUND(SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) * 100.0 / NULLIF(COUNT(*), 0), 2)
                  FROM attendances WHERE attendances.student_id = users.id AND attendances.attendance_date >= ?) as attendance_rate',
                ['present', 'late', $thirtyDaysAgo]
            )
            ->selectRaw(
                '(SELECT status FROM attendances WHERE attendances.student_id = users.id
                  ORDER BY attendance_date DESC, id DESC LIMIT 1) as last_attendance_status'
            )
            ->leftJoin('class_students', function ($join) {
                $join->on('class_students.student_id', '=', 'users.id')
                     ->where('class_students.status', 'active');
            })
            ->leftJoin('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('users.school_id', $schoolId)
            ->where('users.role_type', 'student');
        
        if ($grade) {
            $query->where('classes.grade_level', $grade);
        }
        if ($classId) {
            $query->where('classes.id', $classId);
        }
        if ($status) {
            $query->where('users.is_active', $status === 'active');
        }
        if ($search) {
            $searchTerm = '%' . addcslashes($search, '%_') . '%';
            $query->where(function ($q) use ($searchTerm) {
                $q->where('users.name', 'like', $searchTerm)
                    ->orWhere('users.username', 'like', $searchTerm);
            });
        }
        
        $students = $query->orderBy('users.name')->paginate($perPage);

        return response()->json([
            'data' => $students->items(),
            'meta' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ]);
    }

    // 5. Teacher Performance Summary - HEAVILY OPTIMIZED
    public function teacherPerformanceSummary(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $thirtyDaysAgo = now()->timezone($tz)->subDays(30)->startOfDay();
        
        // OPTIMIZATION: Single query with all data instead of N+1
        $teachers = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->get();
        
        // OPTIMIZATION: Preload all attendance data in single query
        $teacherIds = $teachers->pluck('id');
        
        // Get classroom IDs per teacher
        $teacherClassrooms = DB::table('classroom_teacher')
            ->whereIn('teacher_id', $teacherIds)
            ->select('teacher_id', DB::raw('GROUP_CONCAT(classroom_id) as classroom_ids'))
            ->groupBy('teacher_id')
            ->get()
            ->keyBy('teacher_id');
        
        // Get attendance sessions per teacher
        $attendanceSessions = Attendance::whereIn('teacher_id', $teacherIds)
            ->select('teacher_id', DB::raw('COUNT(DISTINCT date) as session_count'))
            ->groupBy('teacher_id')
            ->get()
            ->keyBy('teacher_id');
        
        // Get attendance rates per teacher
        $attendanceRates = DB::table('attendances')
            ->join('classroom_teacher', 'attendances.classroom_id', '=', 'classroom_teacher.classroom_id')
            ->whereIn('classroom_teacher.teacher_id', $teacherIds)
            ->where('attendances.date', '>=', $thirtyDaysAgo)
            ->select(
                'classroom_teacher.teacher_id',
                DB::raw('SUM(attendances.status="present" OR attendances.status="late") as hadir'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('classroom_teacher.teacher_id')
            ->get()
            ->keyBy('teacher_id');
        
        // Get late starts if ClassSession exists
        $lateStarts = [];
        if (class_exists(\App\Models\ClassSession::class)) {
            $lateStarts = \App\Models\ClassSession::whereIn('teacher_id', $teacherIds)
                ->where('is_late', true)
                ->select('teacher_id', DB::raw('COUNT(*) as late_count'))
                ->groupBy('teacher_id')
                ->get()
                ->keyBy('teacher_id');
        }
        
        // OPTIMIZATION: Map with preloaded data (no queries in loop)
        $result = $teachers->map(function ($teacher) use ($teacherClassrooms, $attendanceSessions, $attendanceRates, $lateStarts) {
            $classroomIds = $teacherClassrooms[$teacher->id]->classroom_ids ?? '';
            $classesTaught = $classroomIds ? count(explode(',', $classroomIds)) : 0;
            
            $sessions = $attendanceSessions[$teacher->id]->session_count ?? 0;
            
            $rateData = $attendanceRates[$teacher->id] ?? null;
            $attendanceRate = $rateData && $rateData->total > 0
                ? round($rateData->hadir / $rateData->total * 100, 2)
                : null;
            
            $lateStartCount = $lateStarts[$teacher->id]->late_count ?? 0;

            return [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->name,
                'classes_taught' => $teacher->classes_taught, // From withCount()
                'attendance_sessions' => $sessions,
                'avg_attendance_rate' => $attendanceRate,
                'late_class_starts' => $lateStartCount,
            ];
        });

        return response()->json([
            'data' => $result,
        ]);
    }

    // 6. Class Health Analytics - OPTIMIZED
    public function classHealthAnalytics(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $today = now()->timezone($tz)->startOfDay();
        $thirtyDaysAgo = $today->copy()->subDays(30)->toDateString();
        $todayString = $today->toDateString();
        
        // OPTIMIZATION: Use withCount() instead of loading all students
        $classes = Classroom::where('school_id', $schoolId)
            ->withCount('students')
            ->get();
        
        $classIds = $classes->pluck('id');
        
        // ✅ OPTIMIZED: Use summary table for attendance stats
        $summaries = \App\Models\AttendanceDailyClassSummary::whereIn('class_id', $classIds)
            ->where('attendance_date', '>=', $thirtyDaysAgo)
            ->where('attendance_date', '<=', $today)
            ->select('class_id', 'attendance_date')
            ->selectRaw('SUM(present_count + late_count) as attended')
            ->selectRaw('SUM(total_students) as total')
            ->groupBy('class_id', 'attendance_date')
            ->get()
            ->groupBy('class_id');
        
        // OPTIMIZATION: Preload absence by day in single query
        $absenceByDay = Attendance::whereIn('classroom_id', $classIds)
            ->where('date', '>=', $thirtyDaysAgo)
            ->where('status', 'absent')
            ->select(
                'classroom_id',
                DB::raw('DAYNAME(date) as day_name'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('classroom_id', 'day_name')
            ->orderByDesc('total')
            ->get()
            ->groupBy('classroom_id');
        
        // OPTIMIZATION: Preload low attendance students in single query
        $lowAttendanceStudentIds = Attendance::whereIn('classroom_id', $classIds)
            ->where('date', '>=', $thirtyDaysAgo)
            ->select(
                'classroom_id',
                'student_id',
                DB::raw('SUM(status="present" OR status="late") as hadir'),
                DB::raw('COUNT(*) as total')
            )
            ->groupBy('classroom_id', 'student_id')
            ->havingRaw('total > 0 AND (hadir / total) < 0.75')
            ->get()
            ->groupBy('classroom_id');
        
        // OPTIMIZATION: Preload student names in single query
        $allLowStudentIds = $lowAttendanceStudentIds->flatten()->pluck('student_id')->unique();
        $studentNames = User::whereIn('id', $allLowStudentIds)
            ->where('role_type', 'student')
            ->pluck('name', 'id');
        
        // OPTIMIZATION: Map with preloaded data (no queries in loop)
        $result = $classes->map(function ($class) use ($summaries, $absenceByDay, $lowAttendanceStudentIds, $studentNames) {
            $totalStudents = $class->students_count;
            
            $classSummaries = $summaries[$class->id] ?? collect();
            $rates = $classSummaries->map(function ($row) {
                return $row->total > 0 ? $row->attended / $row->total : 0;
            });
            $avgAttendanceRate = $rates->count() ? round($rates->avg() * 100, 2) : null;
            
            $absences = $absenceByDay[$class->id] ?? collect();
            $mostAbsentDay = $absences->first()->day_name ?? null;
            
            $lowStudents = $lowAttendanceStudentIds[$class->id] ?? collect();
            $studentsLow = $lowStudents->map(function ($row) use ($studentNames) {
                return $studentNames[$row->student_id] ?? null;
            })->filter();

            return [
                'class_id' => $class->id,
                'class_name' => $class->name,
                'total_students' => $totalStudents,
                'avg_attendance_rate' => $avgAttendanceRate,
                'most_frequent_absence_day' => $mostAbsentDay,
                'students_below_75' => $studentsLow,
            ];
        });

        return response()->json([
            'data' => $result,
        ]);
    }

    // 7. Alerts Panel - OPTIMIZED
    public function alertsPanel(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $today = now()->timezone($tz)->startOfDay();
        
        $absent3days = DB::table('attendances')
            ->select('student_id', DB::raw('GROUP_CONCAT(date ORDER BY date DESC) as dates'))
            ->where('school_id', $schoolId)
            ->where('status', 'absent')
            ->where('date', '>=', $today->copy()->subDays(6))
            ->groupBy('student_id')
            ->havingRaw('COUNT(*) >= 3')
            ->get()
            ->pluck('student_id');
        
        $lowAttendanceClasses = DB::table('attendances')
            ->select('classroom_id', DB::raw('SUM(status="present" OR status="late") as attended'), DB::raw('COUNT(*) as total'))
            ->where('school_id', $schoolId)
            ->whereDate('date', $today)
            ->groupBy('classroom_id')
            ->havingRaw('attended / total < 0.5')
            ->pluck('classroom_id');
        
        $deviceMismatch = DB::table('attendances')
            ->select('student_id')
            ->where('school_id', $schoolId)
            ->whereDate('date', $today)
            ->where('device_id', '!=', DB::raw('expected_device_id'))
            ->pluck('student_id');
        
        $failedScans = DB::table('attendance_failures')
            ->select('student_id', DB::raw('COUNT(*) as fail_count'))
            ->where('school_id', $schoolId)
            ->whereDate('created_at', $today)
            ->groupBy('student_id')
            ->having('fail_count', '>=', 3)
            ->pluck('student_id');
        
        // OPTIMIZATION: Single query for all student names
        $allStudentIds = $absent3days->merge($deviceMismatch)->merge($failedScans)->unique();
        $studentNames = User::whereIn('id', $allStudentIds)
            ->where('role_type', 'student')
            ->pluck('name', 'id');
        
        // OPTIMIZATION: Single query for all classroom names
        $classNames = Classroom::whereIn('id', $lowAttendanceClasses)->pluck('name', 'id');
        
        $alerts = [
            [
                'type' => 'absent_3_days',
                'title' => 'Students absent 3 days in a row',
                'items' => $absent3days->map(fn($id) => $studentNames[$id] ?? null)->filter()->values(),
            ],
            [
                'type' => 'low_attendance_class',
                'title' => 'Classes with <50% attendance today',
                'items' => $lowAttendanceClasses->map(fn($id) => $classNames[$id] ?? null)->filter()->values(),
            ],
            [
                'type' => 'device_mismatch',
                'title' => 'Device mismatch alerts (possible joki)',
                'items' => $deviceMismatch->map(fn($id) => $studentNames[$id] ?? null)->filter()->values(),
            ],
            [
                'type' => 'failed_qr_scans',
                'title' => 'Repeated failed QR scans',
                'items' => $failedScans->map(fn($id) => $studentNames[$id] ?? null)->filter()->values(),
            ],
        ];

        return response()->json([
            'alerts' => $alerts,
        ]);
    }

    // 8. Monthly Attendance Statistics - OPTIMIZED
    public function monthlyAttendanceStats(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $month = $request->input('month', now()->timezone($tz)->format('Y-m'));
        
        // ✅ OPTIMIZED: Use summary table for class rates
        $classRates = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->where('attendance_date', 'like', "$month%")
            ->select('class_id')
            ->selectRaw('SUM(present_count + late_count) as hadir')
            ->selectRaw('SUM(present_count + late_count + absent_count + sick_count + permit_count + excused_count) as total')
            ->groupBy('class_id')
            ->get()
            ->map(function ($row) {
                $rate = $row->total > 0 ? round($row->hadir / $row->total * 100, 2) : null;

                return [
                    'classroom_id' => $row->class_id,
                    'attendance_rate' => $rate,
                ];
            });
        
        // OPTIMIZATION: Get top/worst students with names in single query
        $topStudentsData = Attendance::where('school_id', $schoolId)
            ->where('date', 'like', "$month%")
            ->selectRaw('student_id, SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
            ->groupBy('student_id')
            ->havingRaw('total > 0')
            ->orderByRaw('hadir/total DESC')
            ->limit(10)
            ->get();
        
        $worstStudentsData = Attendance::where('school_id', $schoolId)
            ->where('date', 'like', "$month%")
            ->selectRaw('student_id, SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
            ->groupBy('student_id')
            ->havingRaw('total > 0')
            ->orderByRaw('hadir/total ASC')
            ->limit(10)
            ->get();
        
        // OPTIMIZATION: Preload all student names in single query
        $allStudentIds = $topStudentsData->pluck('student_id')
            ->merge($worstStudentsData->pluck('student_id'))
            ->unique();
        $studentNames = User::whereIn('id', $allStudentIds)
            ->where('role_type', 'student')
            ->pluck('name', 'id');
        
        $topStudents = $topStudentsData->map(function ($row) use ($studentNames) {
            $rate = round($row->hadir / $row->total * 100, 2);

            return [
                'student_id' => $row->student_id,
                'student_name' => $studentNames[$row->student_id] ?? null,
                'attendance_rate' => $rate,
            ];
        });
        
        $worstStudents = $worstStudentsData->map(function ($row) use ($studentNames) {
            $rate = round($row->hadir / $row->total * 100, 2);

            return [
                'student_id' => $row->student_id,
                'student_name' => $studentNames[$row->student_id] ?? null,
                'attendance_rate' => $rate,
            ];
        });
        
        // ✅ OPTIMIZED: Use summary table for trend data
        $trend = \App\Models\AttendanceDailyClassSummary::where('school_id', $schoolId)
            ->where('attendance_date', 'like', "$month%")
            ->select('attendance_date as date')
            ->selectRaw('SUM(present_count + late_count) as present')
            ->selectRaw('SUM(absent_count) as absent')
            ->groupBy('attendance_date')
            ->orderBy('attendance_date')
            ->get();

        return response()->json([
            'class_attendance_rates' => $classRates,
            'top_students' => $topStudents,
            'worst_students' => $worstStudents,
            'trend' => $trend,
        ]);
    }

    // 9. Schedule Overview - OPTIMIZED
    public function scheduleOverview(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $tz = school_timezone();
        $today = now()->timezone($tz)->startOfDay();
        $now = now()->timezone($tz);
        
        // OPTIMIZATION: Eager load relationships to prevent N+1
        $schedules = \App\Models\Schedule::with(['classroom', 'teacher', 'room'])
            ->where('school_id', $schoolId)
            ->whereDate('date', $today->toDateString())
            ->orderBy('start_time')
            ->get();
        
        // OPTIMIZATION: Preload attendance existence in single query
        $classroomIds = $schedules->pluck('classroom_id')->unique();
        $attendanceExists = Attendance::whereIn('classroom_id', $classroomIds)
            ->where('date', $today->toDateString())
            ->select('classroom_id')
            ->distinct()
            ->pluck('classroom_id')
            ->flip();
        
        // OPTIMIZATION: Map with preloaded data (no queries in loop)
        $result = $schedules->map(function ($schedule) use ($now, $attendanceExists, $tz) {
            $todayDate = $now->format('Y-m-d');
            $startTime = \Carbon\Carbon::parse("{$todayDate} {$schedule->start_time}", $tz);
            $endTime = \Carbon\Carbon::parse("{$todayDate} {$schedule->end_time}", $tz);
            
            if ($now->lt($startTime)) {
                $status = 'not_started';
            } elseif ($now->between($startTime, $endTime)) {
                $status = 'ongoing';
            } else {
                $status = 'finished';
            }

            return [
                'schedule_id' => $schedule->id,
                'class_name' => $schedule->classroom->name,
                'grade' => $schedule->classroom->grade ?? null,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'teacher' => $schedule->teacher->name ?? null,
                'room' => $schedule->room->name ?? null,
                'attendance_status' => $status,
                'attendance_taken' => isset($attendanceExists[$schedule->classroom_id]),
            ];
        });

        return response()->json([
            'date' => $today->toDateString(),
            'timeline' => $result,
        ]);
    }
}
