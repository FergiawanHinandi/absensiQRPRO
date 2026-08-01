<?php

namespace App\Http\Controllers\Api\V1\Principal;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PrincipalReportController
 *
 * BE-09 FIX: Controller ini dibuat untuk melengkapi backend endpoint
 * yang dibutuhkan oleh halaman PrincipalReports di frontend.
 */
class PrincipalReportController extends Controller
{
    /**
     * Get reports overview (combined stats)
     */
    public function index(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);

        $startDate = Carbon::create($year, $month, 1)->startOfMonth()->toDateString();
        $endDate = Carbon::create($year, $month, 1)->endOfMonth()->toDateString();

        $stats = Attendance::where('school_id', $schoolId)
            ->whereBetween('attendance_date', [$startDate, $endDate])
            ->selectRaw("
                COUNT(DISTINCT student_id) as unique_students,
                COUNT(DISTINCT attendance_date) as total_days,
                COUNT(DISTINCT CASE WHEN status = 'present' THEN id END) as present_records,
                COUNT(DISTINCT CASE WHEN status = 'late' THEN id END) as late_records,
                COUNT(DISTINCT CASE WHEN status = 'absent' THEN id END) as absent_records,
                COUNT(DISTINCT CASE WHEN status IN ('sick', 'permit') THEN id END) as excused_records,
                COUNT(*) as total_records
            ")
            ->first();

        $attendanceRate = $stats->total_records > 0
            ? round((($stats->present_records + $stats->late_records) / $stats->total_records) * 100, 1)
            : 0;

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'month' => $month,
                    'year' => $year,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                ],
                'summary' => [
                    'unique_students' => $stats->unique_students ?? 0,
                    'total_days' => $stats->total_days ?? 0,
                    'attendance_rate' => $attendanceRate,
                    'present_records' => $stats->present_records ?? 0,
                    'late_records' => $stats->late_records ?? 0,
                    'absent_records' => $stats->absent_records ?? 0,
                    'excused_records' => $stats->excused_records ?? 0,
                    'total_records' => $stats->total_records ?? 0,
                ],
            ],
        ]);
    }

    /**
     * Get attendance report (per kelas per bulan)
     */
    public function attendance(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());
        $classId = $request->input('class_id');

        $query = DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->leftJoin('class_students', function ($join) {
                $join->on('users.id', '=', 'class_students.student_id')
                    ->where('class_students.status', '=', 'active');
            })
            ->leftJoin('classes', 'class_students.class_id', '=', 'classes.id')
            ->where('attendances.school_id', $schoolId)
            ->whereBetween('attendances.attendance_date', [$startDate, $endDate])
            ->groupBy('classes.id', 'classes.name')
            ->selectRaw("
                classes.id as class_id,
                classes.name as class_name,
                COUNT(DISTINCT attendances.student_id) as total_students,
                COUNT(CASE WHEN attendances.status = 'present' THEN 1 END) as present,
                COUNT(CASE WHEN attendances.status = 'late' THEN 1 END) as late,
                COUNT(CASE WHEN attendances.status = 'absent' THEN 1 END) as absent,
                COUNT(CASE WHEN attendances.status IN ('sick', 'permit') THEN 1 END) as excused,
                COUNT(*) as total
            ");

        if ($classId) {
            $query->where('class_students.class_id', $classId);
        }

        $report = $query->get()->map(fn ($row) => [
            'class_id' => $row->class_id,
            'class_name' => $row->class_name ?? 'Tidak Ada Kelas',
            'total_students' => $row->total_students,
            'present' => $row->present,
            'late' => $row->late,
            'absent' => $row->absent,
            'excused' => $row->excused,
            'attendance_rate' => $row->total > 0
                ? round((($row->present + $row->late) / $row->total) * 100, 1)
                : 0,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'report' => $report,
                'period' => compact('startDate', 'endDate'),
            ],
        ]);
    }

    /**
     * Get teacher performance report
     */
    public function teacherPerformance(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $startDate = $request->input('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->input('end_date', now()->endOfMonth()->toDateString());

        $teachers = User::where('school_id', $schoolId)
            ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
            ->where('is_active', true)
            ->select('id', 'name', 'role_type')
            ->withCount([
                'attendances as sessions_taught' => fn ($q) => $q->whereBetween('attendance_date', [$startDate, $endDate]),
            ])
            ->get()
            ->map(fn ($t) => [
                'teacher_id' => $t->id,
                'teacher_name' => $t->name,
                'role' => $t->role_type,
                'sessions_taught' => $t->sessions_taught,
            ])
            ->sortByDesc('sessions_taught')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'teachers' => $teachers,
                'period' => compact('startDate', 'endDate'),
                'total_teachers' => $teachers->count(),
            ],
        ]);
    }

    /**
     * Get student performance report
     */
    public function studentPerformance(Request $request): JsonResponse
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $classId = $request->input('class_id');
        $riskLevel = $request->input('risk_level'); // high, medium, low
        $startDate = $request->input('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->input('end_date', now()->toDateString());

        $query = User::where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->select('id', 'name')
            ->with(['classStudents:id,student_id,class_id', 'classStudents.class:id,name'])
            ->withCount([
                'attendances as total_days' => fn ($q) => $q->whereBetween('attendance_date', [$startDate, $endDate]),
                'attendances as present_days' => fn ($q) => $q
                    ->whereBetween('attendance_date', [$startDate, $endDate])
                    ->whereIn('status', ['present', 'late']),
            ]);

        if ($classId) {
            $query->whereHas('classStudents', fn ($q) => $q->where('class_id', $classId)->where('status', 'active'));
        }

        $students = $query->get()
            ->map(function ($s) {
                $rate = $s->total_days > 0 ? round(($s->present_days / $s->total_days) * 100, 1) : 0;
                $risk = $rate < 60 ? 'high' : ($rate < 80 ? 'medium' : 'low');
                return [
                    'student_id' => $s->id,
                    'student_name' => $s->name,
                    'class_name' => $s->classStudents->first()?->class?->name ?? 'N/A',
                    'attendance_rate' => $rate,
                    'present_days' => $s->present_days,
                    'total_days' => $s->total_days,
                    'risk_level' => $risk,
                ];
            })
            ->when($riskLevel, fn ($col) => $col->filter(fn ($s) => $s['risk_level'] === $riskLevel))
            ->sortBy('attendance_rate')
            ->values();

        return response()->json([
            'success' => true,
            'data' => [
                'students' => $students,
                'total' => $students->count(),
                'period' => compact('startDate', 'endDate'),
            ],
        ]);
    }

    /**
     * Export report
     *
     * ARCH-02 FIX: Endpoint ini sebelumnya hanya stub yang mengembalikan pesan
     * sukses palsu tanpa memproses apa pun. Karena pipeline export asinkron yang
     * sesungguhnya sudah tersedia di AsyncReportExportController (POST /api/v1/reports/export),
     * endpoint ini dikembalikan 501 Not Implemented agar klien tidak mengira
     * export benar-benar diproses.
     */
    public function export(Request $request): JsonResponse
    {
        $request->validate([
            'type' => 'required|in:attendance,teacher_performance,student_performance',
            'format' => 'required|in:excel,pdf,csv',
        ]);

        return response()->json([
            'success' => false,
            'code' => 'NOT_IMPLEMENTED',
            'message' => 'Export belum tersedia di endpoint ini. Gunakan POST /api/v1/reports/export untuk pipeline export asinkron (Excel/PDF/CSV).',
            'data' => [
                'type' => $request->input('type'),
                'format' => $request->input('format'),
                'alternative_endpoint' => '/api/v1/reports/export',
            ],
        ], 501);
    }
}
