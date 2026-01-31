<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\School;

class MonitoringController extends Controller
{
    public function index()
    {
        // 7. Global System Monitoring
        // Total schools, Active users, Failed attendance, Security alerts
        
        $stats = [
            'schools_count' => School::count(),
            'active_students_today' => DB::table('attendances')
                ->whereDate('created_at', today())
                ->distinct('student_id')
                ->count('student_id'),
            'failed_attendance_attempts' => DB::table('attendance_logs') // Assuming logs table
                ->whereDate('created_at', today())
                ->where('status', 'failed')
                ->count(),
            'security_alerts_24h' => DB::table('security_alerts')
                ->where('created_at', '>=', now()->subHours(24))
                ->count(),
            'queue_failure_count' => DB::table('failed_jobs')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }
}
