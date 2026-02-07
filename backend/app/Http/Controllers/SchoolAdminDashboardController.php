<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\School;
use App\Models\Student;
use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SchoolAdminDashboardController extends Controller
{
    // 1. School Profile Summary
    public function schoolProfileSummary(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $school = School::findOrFail($schoolId);
        $totalStudents = Student::where('school_id', $schoolId)->count();
        $totalTeachers = Teacher::where('school_id', $schoolId)->count();
        $activeAcademicYear = AcademicYear::where('school_id', $schoolId)
            ->where('is_active', true)
            ->orderByDesc('start_date')
            ->first();
        $attendanceMethod = $school->attendance_method ?? 'QR';
        $schoolStatus = $school->is_active ? 'Active' : 'Suspended';

        return response()->json([
            'school_name' => $school->name,
            'total_students' => $totalStudents,
            'total_teachers' => $totalTeachers,
            'active_academic_year' => $activeAcademicYear ? $activeAcademicYear->name : null,
            'attendance_method' => $attendanceMethod,
            'school_status' => $schoolStatus,
        ]);
    }

    // 2. Dashboard Summary (metrics)
    public function dashboardSummary(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $cacheKey = "dashboard_summary_school_{$schoolId}";
        $summary = Cache::remember($cacheKey, 60, function () use ($schoolId) {
            $today = Carbon::today();
            $totalStudents = Student::where('school_id', $schoolId)->count();
            $totalTeachers = Teacher::where('school_id', $schoolId)->count();
            $activeClassIds = Classroom::where('school_id', $schoolId)
                ->whereHas('schedules', function ($q) use ($today) {
                    $q->whereDate('date', $today);
                })
                ->pluck('id');
            $classesActiveToday = $activeClassIds->count();
            $attendanceToday = Attendance::where('school_id', $schoolId)
                ->whereDate('date', $today)
                ->selectRaw("
                    SUM(status = 'present') as present,
                    SUM(status = 'late') as late,
                    SUM(status = 'absent') as absent
                ")
                ->first();
            $totalAttendance = ($attendanceToday->present ?? 0) + ($attendanceToday->late ?? 0) + ($attendanceToday->absent ?? 0);
            $attendanceRate = $totalAttendance > 0
                ? round((($attendanceToday->present ?? 0) + ($attendanceToday->late ?? 0)) / $totalAttendance * 100, 2)
                : 0;
            $checkedInStudentIds = Attendance::where('school_id', $schoolId)
                ->whereDate('date', $today)
                ->pluck('student_id');
            $notCheckedIn = Student::where('school_id', $schoolId)
                ->whereNotIn('id', $checkedInStudentIds)
                ->count();
            $trend = Attendance::where('school_id', $schoolId)
                ->where('date', '>=', $today->copy()->subDays(6))
                ->where('date', '<=', $today)
                ->selectRaw('date, SUM(status = "present") as present, SUM(status = "late") as late, SUM(status = "absent") as absent')
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return [
                'total_students' => $totalStudents,
                'total_teachers' => $totalTeachers,
                'classes_active_today' => $classesActiveToday,
                'attendance_today' => [
                    'present' => (int) $attendanceToday->present,
                    'late' => (int) $attendanceToday->late,
                    'absent' => (int) $attendanceToday->absent,
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
        $today = Carbon::today();
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
        $classes = $classesQuery->withCount(['students'])->get();
        $attendance = Attendance::where('school_id', $schoolId)
            ->whereDate('date', $today)
            ->whereIn('classroom_id', $classes->pluck('id'))
            ->selectRaw('
                classroom_id,
                SUM(status = "present") as present,
                SUM(status = "late") as late
            ')
            ->groupBy('classroom_id')
            ->get()
            ->keyBy('classroom_id');
        $result = $classes->map(function ($class) use ($attendance) {
            $present = (int) ($attendance[$class->id]->present ?? 0);
            $late = (int) ($attendance[$class->id]->late ?? 0);
            $total = $class->students_count;
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
            'date' => $today->toDateString(),
            'classes' => $result,
        ]);
    }

    // 4. Student Management List
    public function studentList(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $grade = $request->input('grade');
        $classId = $request->input('class_id');
        $status = $request->input('status');
        $search = $request->input('search');
        $perPage = $request->input('per_page', 20);
        $thirtyDaysAgo = Carbon::today()->subDays(30);
        $query = Student::query()
            ->select([
                'students.id',
                'students.name',
                'students.nisn',
                'students.status',
                'classrooms.name as class_name',
                'classrooms.grade',
                \DB::raw('(SELECT ROUND(SUM(status="present" OR status="late")/COUNT(*),2)*100 FROM attendances WHERE attendances.student_id=students.id AND attendances.date >= "'.$thirtyDaysAgo->toDateString().'" ) as attendance_rate'),
                \DB::raw('(SELECT status FROM attendances WHERE attendances.student_id=students.id ORDER BY date DESC, id DESC LIMIT 1) as last_attendance_status'),
            ])
            ->join('classrooms', 'students.classroom_id', '=', 'classrooms.id')
            ->where('students.school_id', $schoolId);
        if ($grade) {
            $query->where('classrooms.grade', $grade);
        }
        if ($classId) {
            $query->where('classrooms.id', $classId);
        }
        if ($status) {
            $query->where('students.status', $status);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('students.name', 'like', "%$search%")
                    ->orWhere('students.nisn', 'like', "%$search%");
            });
        }
        $students = $query->orderBy('students.name')->paginate($perPage);

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

    // 5. Teacher Performance Summary
    public function teacherPerformanceSummary(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $teachers = Teacher::where('school_id', $schoolId)->get();
        $result = $teachers->map(function ($teacher) {
            $classIds = $teacher->classrooms()->pluck('id');
            $classesTaught = $classIds->count();
            $attendanceSessions = Attendance::whereIn('classroom_id', $classIds)
                ->where('teacher_id', $teacher->id)
                ->distinct('date')
                ->count('date');
            $thirtyDaysAgo = Carbon::today()->subDays(30);
            $studentAttendance = Attendance::whereIn('classroom_id', $classIds)
                ->where('date', '>=', $thirtyDaysAgo)
                ->selectRaw('SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
                ->first();
            $attendanceRate = $studentAttendance->total > 0
                ? round($studentAttendance->hadir / $studentAttendance->total * 100, 2)
                : null;
            $lateStarts = 0;
            if (class_exists(\App\Models\ClassSession::class)) {
                $lateStarts = \App\Models\ClassSession::where('teacher_id', $teacher->id)
                    ->where('is_late', true)
                    ->count();
            }

            return [
                'teacher_id' => $teacher->id,
                'teacher_name' => $teacher->name,
                'classes_taught' => $classesTaught,
                'attendance_sessions' => $attendanceSessions,
                'avg_attendance_rate' => $attendanceRate,
                'late_class_starts' => $lateStarts,
            ];
        });

        return response()->json([
            'data' => $result,
        ]);
    }

    // 6. Class Health Analytics
    public function classHealthAnalytics(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $thirtyDaysAgo = Carbon::today()->subDays(30);
        $classes = Classroom::where('school_id', $schoolId)
            ->withCount('students')
            ->get();
        $result = $classes->map(function ($class) use ($thirtyDaysAgo) {
            $totalStudents = $class->students_count;
            $attendanceStats = Attendance::where('classroom_id', $class->id)
                ->where('date', '>=', $thirtyDaysAgo)
                ->selectRaw('student_id, SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
                ->groupBy('student_id')
                ->get();
            $rates = $attendanceStats->map(function ($row) {
                return $row->total > 0 ? $row->hadir / $row->total : 0;
            });
            $avgAttendanceRate = $rates->count() ? round($rates->avg() * 100, 2) : null;
            $absenceByDay = Attendance::where('classroom_id', $class->id)
                ->where('date', '>=', $thirtyDaysAgo)
                ->where('status', 'absent')
                ->selectRaw('DAYNAME(date) as day_name, COUNT(*) as total')
                ->groupBy('day_name')
                ->orderByDesc('total')
                ->first();
            $mostAbsentDay = $absenceByDay ? $absenceByDay->day_name : null;
            $lowAttendanceStudents = $attendanceStats->filter(function ($row) {
                return $row->total > 0 && ($row->hadir / $row->total) < 0.75;
            })->pluck('student_id');
            $studentsLow = Student::whereIn('id', $lowAttendanceStudents)->pluck('name');

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

    // 7. Alerts Panel
    public function alertsPanel(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $today = Carbon::today();
        $absent3days = DB::table('attendances')
            ->select('student_id', DB::raw('GROUP_CONCAT(date ORDER BY date DESC) as dates'))
            ->where('school_id', $schoolId)
            ->where('status', 'absent')
            ->where('date', '>=', $today->copy()->subDays(6))
            ->groupBy('student_id')
            ->havingRaw('COUNT(*) >= 3')
            ->get()
            ->pluck('student_id');
        $studentsAbsent3 = Student::whereIn('id', $absent3days)->pluck('name');
        $lowAttendanceClasses = DB::table('attendances')
            ->select('classroom_id', DB::raw('SUM(status="present" OR status="late") as attended'), DB::raw('COUNT(*) as total'))
            ->where('school_id', $schoolId)
            ->whereDate('date', $today)
            ->groupBy('classroom_id')
            ->havingRaw('attended / total < 0.5')
            ->pluck('classroom_id');
        $classNames = Classroom::whereIn('id', $lowAttendanceClasses)->pluck('name');
        $deviceMismatch = DB::table('attendances')
            ->select('student_id')
            ->where('school_id', $schoolId)
            ->whereDate('date', $today)
            ->where('device_id', '!=', DB::raw('expected_device_id'))
            ->pluck('student_id');
        $deviceMismatchNames = Student::whereIn('id', $deviceMismatch)->pluck('name');
        $failedScans = DB::table('attendance_failures')
            ->select('student_id', DB::raw('COUNT(*) as fail_count'))
            ->where('school_id', $schoolId)
            ->whereDate('created_at', $today)
            ->groupBy('student_id')
            ->having('fail_count', '>=', 3)
            ->pluck('student_id');
        $failedScanNames = Student::whereIn('id', $failedScans)->pluck('name');
        $alerts = [
            [
                'type' => 'absent_3_days',
                'title' => 'Students absent 3 days in a row',
                'items' => $studentsAbsent3,
            ],
            [
                'type' => 'low_attendance_class',
                'title' => 'Classes with <50% attendance today',
                'items' => $classNames,
            ],
            [
                'type' => 'device_mismatch',
                'title' => 'Device mismatch alerts (possible joki)',
                'items' => $deviceMismatchNames,
            ],
            [
                'type' => 'failed_qr_scans',
                'title' => 'Repeated failed QR scans',
                'items' => $failedScanNames,
            ],
        ];

        return response()->json([
            'alerts' => $alerts,
        ]);
    }

    // 8. Monthly Attendance Statistics
    public function monthlyAttendanceStats(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $month = $request->input('month', Carbon::now()->format('Y-m'));
        $classRates = Attendance::where('school_id', $schoolId)
            ->where('date', 'like', "$month%")
            ->selectRaw('classroom_id, SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
            ->groupBy('classroom_id')
            ->get()
            ->map(function ($row) {
                $rate = $row->total > 0 ? round($row->hadir / $row->total * 100, 2) : null;

                return [
                    'classroom_id' => $row->classroom_id,
                    'attendance_rate' => $rate,
                ];
            });
        $topStudents = Attendance::where('school_id', $schoolId)
            ->where('date', 'like', "$month%")
            ->selectRaw('student_id, SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
            ->groupBy('student_id')
            ->havingRaw('total > 0')
            ->orderByRaw('hadir/total DESC')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $rate = round($row->hadir / $row->total * 100, 2);
                $student = Student::find($row->student_id);

                return [
                    'student_id' => $row->student_id,
                    'student_name' => $student ? $student->name : null,
                    'attendance_rate' => $rate,
                ];
            });
        $worstStudents = Attendance::where('school_id', $schoolId)
            ->where('date', 'like', "$month%")
            ->selectRaw('student_id, SUM(status="present" OR status="late") as hadir, COUNT(*) as total')
            ->groupBy('student_id')
            ->havingRaw('total > 0')
            ->orderByRaw('hadir/total ASC')
            ->limit(10)
            ->get()
            ->map(function ($row) {
                $rate = round($row->hadir / $row->total * 100, 2);
                $student = Student::find($row->student_id);

                return [
                    'student_id' => $row->student_id,
                    'student_name' => $student ? $student->name : null,
                    'attendance_rate' => $rate,
                ];
            });
        $trend = Attendance::where('school_id', $schoolId)
            ->where('date', 'like', "$month%")
            ->selectRaw('date, SUM(status="present" OR status="late") as present, SUM(status="absent") as absent')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'class_attendance_rates' => $classRates,
            'top_students' => $topStudents,
            'worst_students' => $worstStudents,
            'trend' => $trend,
        ]);
    }

    // 9. Schedule Overview
    public function scheduleOverview(Request $request)
    {
        $schoolId = $request->user()->school_id;
        $today = Carbon::today()->toDateString();
        $now = Carbon::now();
        $schedules = \App\Models\Schedule::with(['classroom', 'teacher', 'room'])
            ->where('school_id', $schoolId)
            ->whereDate('date', $today)
            ->orderBy('start_time')
            ->get();
        $result = $schedules->map(function ($schedule) use ($now) {
            if ($now->lt(Carbon::parse($schedule->start_time))) {
                $status = 'not_started';
            } elseif ($now->between(Carbon::parse($schedule->start_time), Carbon::parse($schedule->end_time))) {
                $status = 'ongoing';
            } else {
                $status = 'finished';
            }
            $attendanceExists = Attendance::where('classroom_id', $schedule->classroom_id)
                ->where('date', $schedule->date)
                ->exists();

            return [
                'schedule_id' => $schedule->id,
                'class_name' => $schedule->classroom->name,
                'grade' => $schedule->classroom->grade ?? null,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'teacher' => $schedule->teacher->name ?? null,
                'room' => $schedule->room->name ?? null,
                'attendance_status' => $status,
                'attendance_taken' => $attendanceExists,
            ];
        });

        return response()->json([
            'date' => $today,
            'timeline' => $result,
        ]);
    }
}
