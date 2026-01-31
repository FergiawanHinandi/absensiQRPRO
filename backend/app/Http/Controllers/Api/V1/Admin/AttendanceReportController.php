<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\Classroom;
use App\Models\User;
use App\Exports\AttendanceReportExport;
use Maatwebsite\Excel\Facades\Excel;
use PDF;

class AttendanceReportController extends Controller
{
    // 1. Daily Attendance Report (per class)
    public function daily(Request $request)
    {
        $user = $request->user();
        $date = $request->input('date', Carbon::today()->toDateString());
        $classId = $request->input('class_id');
        $class = Classroom::findOrFail($classId);
        $this->authorize('viewClassReport', $class);
        $students = $class->students()->get();
        $attendances = Attendance::where('class_id', $classId)
            ->whereDate('date', $date)
            ->get();
        $present = $attendances->where('status', 'present')->count();
        $late = $attendances->where('status', 'late')->count();
        $absent = $attendances->where('status', 'absent')->count();
        $total = $students->count();
        $attendanceRate = $total > 0 ? round((($present + $late) / $total) * 100, 2) : 0;
        $studentList = $students->map(function($student) use ($attendances) {
            $attendance = $attendances->where('student_id', $student->id)->first();
            return [
                'id' => $student->id,
                'name' => $student->name,
                'status' => $attendance->status ?? 'absent',
            ];
        });
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
            ]
        ]);
    }

    // 2. Monthly Attendance Summary (per class)
    public function monthly(Request $request)
    {
        $user = $request->user();
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);
        $classId = $request->input('class_id');
        $class = Classroom::findOrFail($classId);
        $this->authorize('viewClassReport', $class);
        $cacheKey = "monthly_attendance_{$classId}_{$month}_{$year}";
        $data = Cache::remember($cacheKey, 300, function() use ($classId, $month, $year, $class) {
            $students = $class->students()->get();
            $attendances = Attendance::where('class_id', $classId)
                ->whereMonth('date', $month)
                ->whereYear('date', $year)
                ->get();
            $schoolDays = $attendances->pluck('date')->unique()->count();
            $total = $students->count();
            $present = $attendances->where('status', 'present')->count();
            $late = $attendances->where('status', 'late')->count();
            $absent = $attendances->where('status', 'absent')->count();
            $avgAttendanceRate = $schoolDays > 0 && $total > 0 ? round((($present + $late) / ($schoolDays * $total)) * 100, 2) : 0;
            $studentBreakdown = $students->map(function($student) use ($attendances, $schoolDays) {
                $studentAttendances = $attendances->where('student_id', $student->id);
                $present = $studentAttendances->where('status', 'present')->count();
                $late = $studentAttendances->where('status', 'late')->count();
                $absent = $studentAttendances->where('status', 'absent')->count();
                $rate = $schoolDays > 0 ? round((($present + $late) / $schoolDays) * 100, 2) : 0;
                return [
                    'id' => $student->id,
                    'name' => $student->name,
                    'present' => $present,
                    'late' => $late,
                    'absent' => $absent,
                    'attendance_rate' => $rate,
                ];
            });
            return [
                'total_school_days' => $schoolDays,
                'avg_attendance_rate' => $avgAttendanceRate,
                'total_late' => $late,
                'total_absent' => $absent,
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
        $attendances = Attendance::where('student_id', $studentId)
            ->whereMonth('date', $month)
            ->whereYear('date', $year)
            ->get();
        $schoolDays = $attendances->pluck('date')->unique()->count();
        $present = $attendances->where('status', 'present')->count();
        $late = $attendances->where('status', 'late')->count();
        $absent = $attendances->where('status', 'absent')->count();
        $attendanceRate = $schoolDays > 0 ? round((($present + $late) / $schoolDays) * 100, 2) : 0;
        $calendar = $attendances->map(function($a) {
            return [
                'date' => $a->date,
                'status' => $a->status,
            ];
        });
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
            ]
        ]);
    }

    // 4. School-wide Monthly Report
    public function schoolMonthly(Request $request)
    {
        $user = $request->user();
        $this->authorize('viewSchoolReport', $user);
        $month = $request->input('month', Carbon::now()->month);
        $year = $request->input('year', Carbon::now()->year);
        $cacheKey = "school_monthly_attendance_{$month}_{$year}";
        $data = Cache::remember($cacheKey, 300, function() use ($month, $year) {
            $classes = Classroom::with('students')->get();
            $result = [];
            foreach ($classes as $class) {
                $students = $class->students;
                $total = $students->count();
                $attendances = Attendance::where('class_id', $class->id)
                    ->whereMonth('date', $month)
                    ->whereYear('date', $year)
                    ->get();
                $schoolDays = $attendances->pluck('date')->unique()->count();
                $present = $attendances->where('status', 'present')->count();
                $late = $attendances->where('status', 'late')->count();
                $absent = $attendances->where('status', 'absent')->count();
                $attendanceRate = $schoolDays > 0 && $total > 0 ? round((($present + $late) / ($schoolDays * $total)) * 100, 2) : 0;
                $result[] = [
                    'class_id' => $class->id,
                    'class_name' => $class->name,
                    'attendance_rate' => $attendanceRate,
                    'late' => $late,
                    'absent' => $absent,
                ];
            }
            $ranked = collect($result)->sortByDesc('attendance_rate')->values();
            return [
                'classes' => $ranked,
                'avg_attendance_rate' => $ranked->avg('attendance_rate'),
                'total_late' => $ranked->sum('late'),
                'total_absent' => $ranked->sum('absent'),
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
