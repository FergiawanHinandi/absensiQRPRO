<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exports\AttendanceReportExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Classroom;
use App\Models\Student;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Facades\Excel;
use PDF;

class AttendanceReportController extends Controller
{
    /**
     * 1. Daily Attendance Report (per class)
     * OPTIMIZED: Single query with JOIN instead of N+1
     */
    public function daily(Request $request)
    {
        $user = $request->user();
        $date = $request->input('date', Carbon::today()->toDateString());
        $classId = $request->input('class_id');
        $class = Classroom::findOrFail($classId);
        $this->authorize('viewClassReport', $class);

        // OPTIMIZED: Single query with LEFT JOIN to get students with attendance
        $studentList = DB::table('class_students')
            ->join('users', 'class_students.student_id', '=', 'users.id')
            ->leftJoin('attendances', function ($join) use ($classId, $date) {
                $join->on('class_students.student_id', '=', 'attendances.student_id')
                    ->where('attendances.class_id', $classId)
                    ->whereDate('attendances.attendance_date', $date);
            })
            ->where('class_students.class_id', $classId)
            ->where('class_students.status', 'active')
            ->select(
                'users.id',
                'users.name',
                DB::raw("COALESCE(attendances.status, 'absent') as status")
            )
            ->orderBy('users.name')
            ->get();

        // OPTIMIZED: Single aggregation query
        $stats = DB::table('attendances')
            ->where('class_id', $classId)
            ->whereDate('attendance_date', $date)
            ->selectRaw("
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
            ")
            ->first();

        $total = $studentList->count();
        $present = (int) ($stats->present ?? 0);
        $late = (int) ($stats->late ?? 0);
        $absent = (int) ($stats->absent ?? 0);
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
                'total_students' => $total,
                'present' => $present,
                'late' => $late,
                'absent' => $absent,
                'attendance_rate' => $attendanceRate,
                'students' => $studentList,
            ],
        ]);
    }

    /**
     * 2. Monthly Attendance Summary (per class)
     * OPTIMIZED: Database-level aggregation instead of collection filtering
     */
    public function monthly(Request $request)
    {
        $user = $request->user();
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);
        $classId = $request->input('class_id');
        $class = Classroom::findOrFail($classId);
        $this->authorize('viewClassReport', $class);

        $cacheKey = "monthly_attendance_{$classId}_{$month}_{$year}_v2";
        $data = Cache::remember($cacheKey, 300, function () use ($classId, $month, $year) {
            // Get school days count
            $schoolDays = DB::table('attendances')
                ->where('class_id', $classId)
                ->whereMonth('attendance_date', $month)
                ->whereYear('attendance_date', $year)
                ->distinct('attendance_date')
                ->count('attendance_date');

            // Get total students
            $totalStudents = DB::table('class_students')
                ->where('class_id', $classId)
                ->where('status', 'active')
                ->count();

            // OPTIMIZED: Single aggregation for class totals
            $classTotals = DB::table('attendances')
                ->where('class_id', $classId)
                ->whereMonth('attendance_date', $month)
                ->whereYear('attendance_date', $year)
                ->selectRaw("
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                    SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                    SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
                    COUNT(*) as total_records
                ")
                ->first();

            // OPTIMIZED: Single query per-student breakdown
            $studentBreakdown = DB::table('class_students')
                ->join('users', 'class_students.student_id', '=', 'users.id')
                ->leftJoin(DB::raw("(
                    SELECT student_id,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
                    FROM attendances
                    WHERE class_id = {$classId}
                        AND EXTRACT(MONTH FROM attendance_date) = {$month}
                        AND EXTRACT(YEAR FROM attendance_date) = {$year}
                    GROUP BY student_id
                ) as att"), 'class_students.student_id', '=', 'att.student_id')
                ->where('class_students.class_id', $classId)
                ->where('class_students.status', 'active')
                ->select(
                    'users.id',
                    'users.name',
                    DB::raw('COALESCE(att.present, 0) as present'),
                    DB::raw('COALESCE(att.late, 0) as late'),
                    DB::raw('COALESCE(att.absent, 0) as absent'),
                    DB::raw($schoolDays > 0
                        ? "ROUND(((COALESCE(att.present, 0) + COALESCE(att.late, 0)) / {$schoolDays}) * 100, 2)"
                        : '0'
                    . ' as attendance_rate')
                )
                ->orderBy('users.name')
                ->get();

            $avgRate = $schoolDays > 0 && $totalStudents > 0
                ? round((((int) ($classTotals->present ?? 0) + (int) ($classTotals->late ?? 0))
                    / ($schoolDays * $totalStudents)) * 100, 2)
                : 0;

            return [
                'total_school_days' => $schoolDays,
                'avg_attendance_rate' => $avgRate,
                'total_late' => (int) ($classTotals->late ?? 0),
                'total_absent' => (int) ($classTotals->absent ?? 0),
                'students' => $studentBreakdown,
            ];
        });

        Log::channel('audit')->info('attendance_report_viewed', [
            'user_id' => $user->id,
            'type' => 'monthly',
            'class_id' => $classId,
            'month' => $month,
            'year' => $year,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // 3. Student Monthly Report
    public function studentMonthly(Request $request, $studentId)
    {
        $user = $request->user();
        $student = Student::findOrFail($studentId);
        $this->authorize('viewStudentReport', $student);
        $month = Carbon::now()->month;
        $year = Carbon::now()->year;

        // OPTIMIZED: Single aggregation query
        $stats = DB::table('attendances')
            ->where('student_id', $studentId)
            ->whereMonth('attendance_date', $month)
            ->whereYear('attendance_date', $year)
            ->selectRaw("
                COUNT(DISTINCT attendance_date) as school_days,
                SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
            ")
            ->first();

        $schoolDays = (int) ($stats->school_days ?? 0);
        $present = (int) ($stats->present ?? 0);
        $late = (int) ($stats->late ?? 0);
        $absent = (int) ($stats->absent ?? 0);
        $attendanceRate = $schoolDays > 0 ? round((($present + $late) / $schoolDays) * 100, 2) : 0;

        $calendar = DB::table('attendances')
            ->where('student_id', $studentId)
            ->whereMonth('attendance_date', $month)
            ->whereYear('attendance_date', $year)
            ->select('attendance_date as date', 'status')
            ->orderBy('attendance_date')
            ->get();

        Log::channel('audit')->info('attendance_report_viewed', [
            'user_id' => $user->id,
            'type' => 'student_monthly',
            'student_id' => $studentId,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'attendance_rate' => $attendanceRate,
                'late_count' => $late,
                'absent_count' => $absent,
                'calendar' => $calendar,
            ],
        ]);
    }

    /**
     * 4. School-wide Monthly Report
     * OPTIMIZED: Single aggregation query instead of N+1 loop
     */
    public function schoolMonthly(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewSchoolReport', $user);
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);
        $schoolId = $user->school_id;

        $cacheKey = "school_monthly_attendance_{$schoolId}_{$month}_{$year}_v2";
        $data = Cache::remember($cacheKey, 300, function () use ($schoolId, $month, $year) {
            // OPTIMIZED: Single query for all classes with aggregation
            $classStats = DB::table('classes')
                ->leftJoin(DB::raw("(
                    SELECT class_id,
                        COUNT(DISTINCT attendance_date) as school_days,
                        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
                        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late,
                        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent
                    FROM attendances
                    WHERE school_id = {$schoolId}
                        AND EXTRACT(MONTH FROM attendance_date) = {$month}
                        AND EXTRACT(YEAR FROM attendance_date) = {$year}
                    GROUP BY class_id
                ) as att"), 'classes.id', '=', 'att.class_id')
                ->leftJoin(DB::raw("(
                    SELECT class_id, COUNT(*) as total_students
                    FROM class_students
                    WHERE status = 'active'
                    GROUP BY class_id
                ) as cs"), 'classes.id', '=', 'cs.class_id')
                ->where('classes.school_id', $schoolId)
                ->where('classes.is_active', true)
                ->select(
                    'classes.id as class_id',
                    'classes.name as class_name',
                    'classes.grade_level',
                    DB::raw('COALESCE(cs.total_students, 0) as total_students'),
                    DB::raw('COALESCE(att.school_days, 0) as school_days'),
                    DB::raw('COALESCE(att.present, 0) as present'),
                    DB::raw('COALESCE(att.late, 0) as late'),
                    DB::raw('COALESCE(att.absent, 0) as absent'),
                    DB::raw('CASE
                        WHEN COALESCE(att.school_days, 0) > 0 AND COALESCE(cs.total_students, 0) > 0
                        THEN ROUND(((COALESCE(att.present, 0) + COALESCE(att.late, 0))::numeric
                            / (COALESCE(att.school_days, 0) * COALESCE(cs.total_students, 0))) * 100, 2)
                        ELSE 0
                    END as attendance_rate')
                )
                ->orderByDesc('attendance_rate')
                ->get();

            $classes = $classStats->map(fn ($c) => [
                'class_id' => $c->class_id,
                'class_name' => $c->class_name,
                'attendance_rate' => (float) $c->attendance_rate,
                'late' => (int) $c->late,
                'absent' => (int) $c->absent,
            ]);

            return [
                'classes' => $classes,
                'avg_attendance_rate' => round($classes->avg('attendance_rate'), 2),
                'total_late' => $classes->sum('late'),
                'total_absent' => $classes->sum('absent'),
            ];
        });

        Log::channel('audit')->info('attendance_report_viewed', [
            'user_id' => $user->id,
            'type' => 'school_monthly',
            'month' => $month,
            'year' => $year,
        ]);

        return response()->json(['success' => true, 'data' => $data]);
    }

    // 5. Export PDF
    public function exportPdf(Request $request)
    {
        $user = $request->user();
        $type = $request->input('type');
        $params = $request->input('params', []);
        $data = [];
        switch ($type) {
            case 'daily':
                $data = $this->daily(new Request($params))->getData()->data;
                $view = 'pdf.attendance_daily';
                break;
            case 'monthly':
                $data = $this->monthly(new Request($params))->getData()->data;
                $view = 'pdf.attendance_monthly';
                break;
            case 'student':
                $data = $this->studentMonthly(new Request($params), $params['student_id'])->getData()->data;
                $view = 'pdf.attendance_student';
                break;
            default:
                abort(400, 'Invalid export type');
        }
        $pdf = PDF::loadView($view, ['data' => $data]);
        Log::channel('audit')->info('attendance_report_exported', [
            'user_id' => $user->id,
            'type' => $type,
        ]);

        return $pdf->download("attendance_report_{$type}.pdf");
    }

    // 6. Export Excel
    public function exportExcel(Request $request)
    {
        $user = $request->user();
        $type = $request->input('type');
        $params = $request->input('params', []);
        $export = new AttendanceReportExport($type, $params);
        Log::channel('audit')->info('attendance_report_exported', [
            'user_id' => $user->id,
            'type' => $type,
        ]);

        return Excel::download($export, "attendance_report_{$type}.xlsx");
    }
}
