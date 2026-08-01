<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Get Super Admin Dashboard Statistics
     */
    public function index(Request $request)
    {
        // Pastikan user adalah super admin
        if ($request->user()->role_type !== 'super_admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Super Admin access only.',
            ], 403);
        }

        // 1. Total Sekolah Aktif
        $totalSchools = School::where('is_active', true)->count();
        $totalSchoolsLastMonth = School::where('is_active', true)
            ->where('created_at', '<=', now()->subMonth())
            ->count();
        $schoolGrowth = $totalSchoolsLastMonth > 0
            ? round((($totalSchools - $totalSchoolsLastMonth) / $totalSchoolsLastMonth) * 100, 1)
            : 0;

        // 2. Total Siswa & Guru
        $totalStudents = User::where('role_type', 'student')
            ->where('is_active', true)
            ->count();
        $totalTeachers = User::where('role_type', 'teacher')
            ->where('is_active', true)
            ->count();

        // Determine Period
        $period = $request->input('period', 'today'); // today, week, month

        // 3. Absensi (Period-based)
        $attendanceQuery = Attendance::query();
        if ($period === 'week') {
            $attendanceQuery->whereBetween('attendance_date', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'month') {
            $attendanceQuery->whereMonth('attendance_date', now()->month)->whereYear('attendance_date', now()->year);
        } else {
            $attendanceQuery->whereDate('attendance_date', today());
        }
        $attendancePeriod = $attendanceQuery->count();

        // 4. Revenue (Period-based)
        $revenueQuery = \App\Models\Payment::where('status', 'paid');
        if ($period === 'week') {
            $revenueQuery->whereBetween('payment_date', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'month') {
            $revenueQuery->whereMonth('payment_date', now()->month)->whereYear('payment_date', now()->year);
        } else {
            $revenueQuery->whereDate('payment_date', today());
        }
        $revenuePeriod = $revenueQuery->sum('amount');

        // 5. Sekolah Terbaru
        $recentSchools = School::with('users')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($school) {
                return [
                    'id' => $school->id,
                    'name' => $school->name,
                    'students' => $school->users()->where('role_type', 'student')->count(),
                    'status' => $school->is_active ? 'active' : 'inactive',
                    'created_at' => $school->created_at->format('Y-m-d'),
                ];
            });

        // 6. Statistik per Jenjang
        $schoolsByLevel = School::select('school_level', DB::raw('count(*) as total'))
            ->where('is_active', true)
            ->groupBy('school_level')
            ->get()
            ->pluck('total', 'school_level');

        // 7. Top 5 Sekolah dengan Siswa Terbanyak
        $topSchools = School::withCount(['users' => function ($query) {
            $query->where('role_type', 'student');
        }])
            ->orderBy('users_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($school) {
                return [
                    'name' => $school->name,
                    'students' => $school->users_count,
                    'level' => $school->school_level,
                ];
            });

        // 8. Statistik Absensi 7 Hari Terakhir
        $attendanceLast7Days = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $count = Attendance::whereDate('attendance_date', $date)->count();
            $attendanceLast7Days[] = [
                'date' => $date->format('Y-m-d'),
                'day' => $date->format('D'),
                'count' => $count,
            ];
        }

        // 9. System Status (Real Check)
        try {
            DB::connection()->getPdo();
            $dbStatus = 'operational';
        } catch (\Exception $e) {
            $dbStatus = 'down';
        }

        // ARCH-02 FIX: Storage status dihitung dari tes writability nyata,
        // bukan placeholder hardcoded.
        $storageStatus = 'operational';
        try {
            $testFile = storage_path('app/health-check-'.time().'.tmp');
            if (false === @file_put_contents($testFile, 'ok')) {
                $storageStatus = 'degraded';
            } else {
                @unlink($testFile);
            }
        } catch (\Exception $e) {
            $storageStatus = 'down';
        }

        // ARCH-02 FIX: Queue Worker status dihitung dari heartbeat worker + jumlah
        // job antrean, bukan placeholder hardcoded.
        $queueStatus = 'operational';
        try {
            $heartbeat = \Illuminate\Support\Facades\Cache::get('queue_worker:heartbeat');
            $pendingJobs = \Illuminate\Support\Facades\DB::table('jobs')->count();
            if ($pendingJobs > 0 && ! $heartbeat) {
                $queueStatus = 'degraded'; // Ada antrean tapi tidak ada heartbeat worker
            }
        } catch (\Exception $e) {
            $queueStatus = 'unknown';
        }

        $systemStatus = [
            ['service' => 'API Server', 'status' => 'operational', 'uptime' => '99.98%'],
            ['service' => 'Database', 'status' => $dbStatus, 'uptime' => '99.95%'],
            ['service' => 'Storage', 'status' => $storageStatus, 'uptime' => '100%'],
            ['service' => 'Queue Worker', 'status' => $queueStatus, 'uptime' => '99.92%'],
        ];

        // 10. Audit Logs (Recent Activities)
        $recentActivities = \App\Models\AuditLog::with(['user:id,name', 'school:id,name'])
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'user' => $log->user->name ?? 'System',
                    'school' => $log->school->name ?? '-',
                    'action' => $log->action,
                    'description' => $log->description,
                    'time' => $log->created_at->diffForHumans(),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'stats' => [
                    'total_schools' => $totalSchools,
                    'school_growth' => $schoolGrowth,
                    'total_students' => $totalStudents,
                    'total_teachers' => $totalTeachers,
                    'attendance_today' => $attendancePeriod,
                    'attendance_count' => $attendancePeriod,
                    'revenue_amount' => $revenuePeriod,
                    'revenue_this_month' => $revenuePeriod,
                    'period' => $period, // Send back specific info
                ],
                'recent_schools' => $recentSchools,
                'schools_by_level' => $schoolsByLevel,
                'top_schools' => $topSchools,
                'attendance_chart' => $attendanceLast7Days,
                'system_status' => $systemStatus,
                'recent_activities' => $recentActivities,
            ],
        ]);
    }

    /**
     * Get Schools List
     */
    public function schools(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');

        $query = School::query();

        if ($search) {
            $query->where('name', 'ILIKE', "%{$search}%")
                ->orWhere('address', 'ILIKE', "%{$search}%");
        }

        $schools = $query->withCount(['users' => function ($q) {
            $q->where('role_type', 'student');
        }])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $schools,
        ]);
    }
}
