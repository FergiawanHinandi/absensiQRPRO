<?php

namespace App\Http\Controllers\Api\V1\Principal;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PrincipalDashboardController extends Controller
{
    /**
     * Get principal dashboard overview
     * 
     * @return \Illuminate\Http\JsonResponse
     */
    public function index()
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        
        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Principal dashboard endpoint',
                'school_id' => $schoolId,
            ]
        ]);
    }

    /**
     * Get attendance overview for the school
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function attendanceOverview(Request $request)
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $range = $request->get('range', '30d');
        
        // Calculate date range
        $daysBack = $range === '7d' ? 7 : 30;
        $startDate = Carbon::now()->subDays($daysBack);
        $today = Carbon::today();

        // School summary statistics
        $totalStudents = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        $totalClasses = DB::table('classes')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->count();

        // Today's attendance summary
        $todayAttendance = DB::table('attendances')
            ->where('attendances.school_id', $schoolId)
            ->whereDate('attendances.attendance_date', $today)
            ->select(
                DB::raw('COUNT(*) as total'),
                DB::raw('SUM(CASE WHEN attendances.status = "present" THEN 1 ELSE 0 END) as present'),
                DB::raw('SUM(CASE WHEN attendances.status = "late" THEN 1 ELSE 0 END) as late'),
                DB::raw('SUM(CASE WHEN attendances.status = "absent" THEN 1 ELSE 0 END) as absent')
            )
            ->first();

        // Calculate attendance rate
        $attendanceRate = 0;
        if ($todayAttendance && $todayAttendance->total > 0) {
            $attendanceRate = round(($todayAttendance->present / $todayAttendance->total) * 100, 1);
        }

        // Monthly trend data
        $monthlyTrend = DB::table('attendances')
            ->where('attendances.school_id', $schoolId)
            ->where('attendances.attendance_date', '>=', $startDate)
            ->select(
                DB::raw('DATE(attendances.attendance_date) as date'),
                DB::raw('COUNT(*) as total_students'),
                DB::raw('SUM(CASE WHEN attendances.status = "present" THEN 1 ELSE 0 END) as present'),
                DB::raw('SUM(CASE WHEN attendances.status = "late" THEN 1 ELSE 0 END) as late'),
                DB::raw('SUM(CASE WHEN attendances.status = "absent" THEN 1 ELSE 0 END) as absent')
            )
            ->groupBy(DB::raw('DATE(attendances.attendance_date)'))
            ->orderBy('date')
            ->get()
            ->map(function ($item) {
                $attendanceRate = $item->total_students > 0 
                    ? round(($item->present / $item->total_students) * 100, 1) 
                    : 0;
                
                return [
                    'date' => $item->date,
                    'attendance_rate' => $attendanceRate,
                    'total_students' => $item->total_students,
                    'present' => $item->present,
                    'late' => $item->late,
                    'absent' => $item->absent,
                ];
            });

        // Class breakdown (simplified)
        $classBreakdown = DB::table('classes')
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->select(
                'classes.id as class_id',
                'classes.name as class_name'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'class_id' => $item->class_id,
                    'class_name' => $item->class_name,
                    'total_students' => 0, // Simplified for now
                    'attendance_rate' => 0,
                    'present' => 0,
                    'late' => 0,
                    'absent' => 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'school_summary' => [
                    'total_students' => $totalStudents,
                    'total_classes' => $totalClasses,
                    'attendance_rate' => $attendanceRate,
                    'present_today' => $todayAttendance->present ?? 0,
                    'late_today' => $todayAttendance->late ?? 0,
                    'absent_today' => $todayAttendance->absent ?? 0,
                ],
                'monthly_trend' => $monthlyTrend,
                'class_breakdown' => $classBreakdown,
            ]
        ]);
    }

    /**
     * Get class performance comparison
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function classPerformance(Request $request)
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;

        // Performance ranking by class (simplified)
        $performanceRanking = DB::table('classes')
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->select(
                'classes.id as class_id',
                'classes.name as class_name'
            )
            ->get()
            ->map(function ($item, $index) {
                return [
                    'class_id' => $item->class_id,
                    'class_name' => $item->class_name,
                    'total_students' => 0, // Simplified
                    'attendance_rate' => 85.5, // Mock data
                    'rank' => $index + 1,
                    'trend' => 'stable',
                ];
            });

        // Grade comparison (simplified)
        $gradeComparison = DB::table('classes')
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->select(
                DB::raw('"Grade " || grade_level as grade_name'),
                DB::raw('COUNT(DISTINCT classes.id) as total_classes'),
                DB::raw('grade_level')
            )
            ->groupBy('grade_level')
            ->get()
            ->map(function ($item) {
                return [
                    'grade_name' => $item->grade_name,
                    'total_classes' => $item->total_classes,
                    'avg_attendance_rate' => 85.0, // Mock data
                    'best_class' => 'TBD',
                    'worst_class' => 'TBD',
                ];
            });

        // Subject performance (simplified)
        $subjectPerformance = collect([
            [
                'subject_name' => 'Matematika',
                'avg_attendance_rate' => 87.5,
                'total_sessions' => 20,
                'classes_count' => 3,
            ],
            [
                'subject_name' => 'Bahasa Indonesia',
                'avg_attendance_rate' => 89.2,
                'total_sessions' => 18,
                'classes_count' => 3,
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'performance_ranking' => $performanceRanking,
                'grade_comparison' => $gradeComparison,
                'subject_performance' => $subjectPerformance,
            ]
        ]);
    }

    /**
     * Get high-risk students analysis
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function riskStudents(Request $request)
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        $limit = $request->get('limit', 50);
        $daysBack = 30;
        $startDate = Carbon::now()->subDays($daysBack);

        // High risk students calculation (simplified)
        $highRiskStudents = DB::table('users')
            ->leftJoin('attendances', function ($join) use ($startDate) {
                $join->on('users.id', '=', 'attendances.student_id')
                     ->where('attendances.attendance_date', '>=', $startDate);
            })
            ->where('users.school_id', $schoolId)
            ->where('users.role_type', 'student')
            ->where('users.is_active', true)
            ->select(
                'users.id as student_id',
                'users.name as student_name',
                DB::raw('COUNT(attendances.id) as total_records'),
                DB::raw('SUM(CASE WHEN attendances.status = "absent" THEN 1 ELSE 0 END) as absent_days'),
                DB::raw('SUM(CASE WHEN attendances.status = "late" THEN 1 ELSE 0 END) as late_days'),
                DB::raw('SUM(CASE WHEN attendances.status IN ("present", "late") THEN 1 ELSE 0 END) as attended_days'),
                DB::raw('MAX(attendances.attendance_date) as last_attendance')
            )
            ->groupBy('users.id', 'users.name')
            ->get()
            ->map(function ($item) {
                $attendanceRate = $item->total_records > 0 
                    ? round(($item->attended_days / $item->total_records) * 100, 1) 
                    : 0;
                
                // Determine risk level
                $riskLevel = 'low';
                if ($attendanceRate < 60) {
                    $riskLevel = 'high';
                } elseif ($attendanceRate < 80) {
                    $riskLevel = 'medium';
                }
                
                return [
                    'student_id' => $item->student_id,
                    'student_name' => $item->student_name,
                    'class_name' => 'TBD', // Simplified
                    'attendance_rate' => $attendanceRate,
                    'absent_days' => $item->absent_days,
                    'late_days' => $item->late_days,
                    'risk_level' => $riskLevel,
                    'last_attendance' => $item->last_attendance,
                    'consecutive_absences' => $item->absent_days, // Simplified
                ];
            })
            ->sortBy('attendance_rate')
            ->take($limit)
            ->values();

        // Risk summary
        $totalStudents = DB::table('users')
            ->where('school_id', $schoolId)
            ->where('role_type', 'student')
            ->where('is_active', true)
            ->count();

        $highRiskCount = $highRiskStudents->where('risk_level', 'high')->count();
        $mediumRiskCount = $highRiskStudents->where('risk_level', 'medium')->count();
        $lowRiskCount = $highRiskStudents->where('risk_level', 'low')->count();

        // Class risk breakdown (simplified)
        $classRiskBreakdown = DB::table('classes')
            ->where('classes.school_id', $schoolId)
            ->where('classes.is_active', true)
            ->select(
                'classes.name as class_name'
            )
            ->get()
            ->map(function ($item) {
                return [
                    'class_name' => $item->class_name,
                    'total_students' => 0, // Simplified
                    'high_risk_students' => 0,
                    'risk_percentage' => 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'high_risk_students' => $highRiskStudents,
                'risk_summary' => [
                    'total_students' => $totalStudents,
                    'high_risk_count' => $highRiskCount,
                    'medium_risk_count' => $mediumRiskCount,
                    'low_risk_count' => $lowRiskCount,
                    'critical_threshold' => 60,
                ],
                'class_risk_breakdown' => $classRiskBreakdown,
            ]
        ]);
    }

    /**
     * Get reports summary for principal
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function reportsSummary(Request $request)
    {
        $principal = Auth::user();
        $schoolId = $principal->school_id;
        
        return response()->json([
            'success' => true,
            'data' => [
                'message' => 'Reports summary endpoint',
                'school_id' => $schoolId,
            ]
        ]);
    }
}