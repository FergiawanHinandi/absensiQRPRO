<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /**
     * Get Teacher Recognition Data
     * - Teacher with best class attendance
     * - Classes with highest improvement
     */
    public function teacherRecognition(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // 1. Teacher whose class has best attendance rate (This Month)
        // Join classes -> teacher -> attendances
        $startOfMonth = Carbon::now()->startOfMonth()->toDateString();
        $today = Carbon::now()->toDateString();

        $bestClassAttendance = DB::table('classes')
            ->join('users as teachers', 'classes.homeroom_teacher_id', '=', 'teachers.id')
            ->leftJoin('attendances', function ($join) use ($startOfMonth, $today) {
                $join->on('classes.id', '=', 'attendances.class_id')
                    ->whereBetween('attendances.attendance_date', [$startOfMonth, $today]);
            })
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->select(
                'teachers.id as teacher_id',
                'teachers.name as teacher_name',
                'classes.id as class_id',
                'classes.name as class_name',
                DB::raw('count(attendances.id) as total_records'),
                DB::raw("sum(case when attendances.status in ('present', 'late') then 1 else 0 end) as present_count")
            )
            ->groupBy('teachers.id', 'teachers.name', 'classes.id', 'classes.name')
            ->having('total_records', '>', 0)
            ->get()
            ->map(function ($item) {
                $rate = $item->total_records > 0
                    ? round(($item->present_count / $item->total_records) * 100, 1)
                    : 0;
                $item->attendance_rate = $rate;

                return $item;
            })
            ->sortByDesc('attendance_rate')
            ->values()
            ->first(); // Get top 1

        // 2. Classes with highest attendance improvement
        // Compare Last Month vs This Month (so far)
        // Or Last Week vs This Week. Let's use Last Month vs This Month for stability.

        $lastMonthStart = Carbon::now()->subMonth()->startOfMonth()->toDateString();
        $lastMonthEnd = Carbon::now()->subMonth()->endOfMonth()->toDateString();

        // Get Stats for Both Periods - SECURITY: Using selectRaw with parameter bindings
        $improvementStats = DB::table('classes')
            ->leftJoin('attendances', 'classes.id', '=', 'attendances.class_id')
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->selectRaw('classes.id, classes.name')
            ->selectRaw(
                "sum(case when attendances.attendance_date BETWEEN ? AND ? then 1 else 0 end) as current_total",
                [$startOfMonth, $today]
            )
            ->selectRaw(
                "sum(case when attendances.attendance_date BETWEEN ? AND ? AND attendances.status IN ('present', 'late') then 1 else 0 end) as current_present",
                [$startOfMonth, $today]
            )
            ->selectRaw(
                "sum(case when attendances.attendance_date BETWEEN ? AND ? then 1 else 0 end) as prev_total",
                [$lastMonthStart, $lastMonthEnd]
            )
            ->selectRaw(
                "sum(case when attendances.attendance_date BETWEEN ? AND ? AND attendances.status IN ('present', 'late') then 1 else 0 end) as prev_present",
                [$lastMonthStart, $lastMonthEnd]
            )
            ->groupBy('classes.id', 'classes.name')
            ->get()
            ->map(function ($item) {
                $currentRate = $item->current_total > 0
                    ? ($item->current_present / $item->current_total) * 100
                    : 0;
                $prevRate = $item->prev_total > 0
                    ? ($item->prev_present / $item->prev_total) * 100
                    : 0;

                $improvement = $currentRate - $prevRate;

                return [
                    'class_id' => $item->id,
                    'class_name' => $item->name,
                    'current_rate' => round($currentRate, 1),
                    'prev_rate' => round($prevRate, 1),
                    'improvement' => round($improvement, 1),
                ];
            })
            ->filter(function ($item) {
                // Filter out if no data in both periods to avoid noise
                return $item['current_rate'] > 0 || $item['prev_rate'] > 0;
            })
            ->sortByDesc('improvement')
            ->values()
            ->take(5); // Top 5

        return response()->json([
            'success' => true,
            'data' => [
                'best_attendance_teacher' => $bestClassAttendance,
                'most_improved_classes' => $improvementStats,
            ],
        ]);
    }

    /**
     * Get Student Semester Attendance Summary
     * - Total school days
     * - Present %
     * - Late count
     * - Absence count
     * - Reward tier achieved
     */
    public function studentSemesterSummary(Request $request, $studentId)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // Verify student belongs to school
        $student = \App\Models\User::where('id', $studentId)
            ->where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->firstOrFail();

        // Determine Current Semester Range
        $now = Carbon::now();
        $year = $now->year;
        $month = $now->month;

        if ($month >= 7) {
            // Semester Ganjil (July - Dec)
            $semesterLabel = "Ganjil $year/".($year + 1);
            $startDate = Carbon::create($year, 7, 1)->startOfDay();
            $endDate = Carbon::create($year, 12, 31)->endOfDay();
        } else {
            // Semester Genap (Jan - June)
            $semesterLabel = 'Genap '.($year - 1)."/$year";
            $startDate = Carbon::create($year, 1, 1)->startOfDay();
            $endDate = Carbon::create($year, 6, 30)->endOfDay();
        }

        // Aggregation Query
        $stats = DB::table('attendances')
            ->where('student_id', $studentId)
            ->whereBetween('attendance_date', [$startDate->toDateString(), $endDate->toDateString()])
            ->select(
                DB::raw('count(*) as total_days'),
                DB::raw("sum(case when status in ('present', 'late') then 1 else 0 end) as present_count"),
                DB::raw("sum(case when status = 'late' then 1 else 0 end) as late_count"),
                DB::raw("sum(case when status = 'absent' then 1 else 0 end) as absence_count")
            )
            ->first();

        $totalDays = $stats->total_days ?? 0;
        $presentCount = $stats->present_count ?? 0;
        $lateCount = $stats->late_count ?? 0;
        $absenceCount = $stats->absence_count ?? 0;

        $presentPercentage = $totalDays > 0
            ? round(($presentCount / $totalDays) * 100, 1)
            : 0;

        // Reward Tier (from User model accessor)
        $rewardTier = $student->level; // Accessor: getLevelAttribute

        return response()->json([
            'success' => true,
            'data' => [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'semester_label' => $semesterLabel,
                'period' => [
                    'start' => $startDate->toDateString(),
                    'end' => $endDate->toDateString(),
                ],
                'total_school_days' => $totalDays,
                'present_percentage' => $presentPercentage,
                'late_count' => (int) $lateCount,
                'absence_count' => (int) $absenceCount,
                'reward_tier' => $rewardTier,
            ],
        ]);
    }

    /**
     * Get Generated Reports List
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $reports = DB::table('attendance_reports')
            ->where('school_id', $schoolId)
            ->orderBy('report_date', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total' => $reports->count(),
                'reports' => $reports,
            ],
        ]);
    }
}
