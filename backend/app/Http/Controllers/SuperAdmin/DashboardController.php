<?php

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\User;
use App\Models\SecurityEvent;
use App\Models\Activity;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Display dashboard overview
     */
    public function overview()
    {
        // Stats Cards Data
        $activeSchools = School::where('is_active', true)->count();
        $totalSchools = School::count();
        $activeSchoolsPercentage = $totalSchools > 0 
            ? round(($activeSchools / $totalSchools) * 100) 
            : 0;
        
        $totalUsers = User::count();
        
        $securityEvents = SecurityEvent::where('created_at', '>=', now()->subDay())->count();
        
        $systemUptime = $this->getSystemUptime();
        
        // Package Distribution
        $basicPackageCount = School::where('package', 'Basic')->count();
        $professionalPackageCount = School::where('package', 'Professional')->count();
        $enterprisePackageCount = School::where('package', 'Enterprise')->count();
        
        // Recent Activities
        $activities = Activity::with(['school', 'user'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($activity) {
                return (object) [
                    'time' => $activity->created_at->format('H:i:s'),
                    'school_name' => $activity->school->name ?? 'N/A',
                    'action' => $activity->action,
                    'user_email' => $activity->user->email ?? 'System',
                    'status' => $activity->status ?? 'success',
                ];
            });
        
        // Navbar Data
        $notificationCount = auth()->user()->unreadNotifications()->count();
        $messageCount = auth()->user()->unreadMessages()->count();
        
        return view('super-admin.dashboard.overview', compact(
            'activeSchools',
            'totalSchools',
            'activeSchoolsPercentage',
            'totalUsers',
            'securityEvents',
            'systemUptime',
            'basicPackageCount',
            'professionalPackageCount',
            'enterprisePackageCount',
            'activities',
            'notificationCount',
            'messageCount'
        ));
    }
    
    /**
     * Display school statistics
     */
    public function schoolStats()
    {
        $totalSchools = School::count();
        $activeSchools = School::where('is_active', true)->count();
        $inactiveSchools = School::where('is_active', false)->count();
        
        // Top Schools by Activity
        $topSchools = School::withCount(['attendances' => function ($query) {
                $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
            }])
            ->orderBy('attendances_count', 'desc')
            ->limit(5)
            ->get()
            ->map(function ($school) {
                return (object) [
                    'rank' => 0, // Will be set in view
                    'name' => $school->name,
                    'package' => $school->package,
                    'users' => $school->users()->count(),
                    'attendance' => $school->attendances_count,
                    'status' => $school->is_active ? 'Aktif' : 'Nonaktif',
                ];
            });
        
        $notificationCount = auth()->user()->unreadNotifications()->count();
        $messageCount = auth()->user()->unreadMessages()->count();
        
        return view('super-admin.dashboard.school-stats', compact(
            'totalSchools',
            'activeSchools',
            'inactiveSchools',
            'topSchools',
            'notificationCount',
            'messageCount'
        ));
    }
    
    /**
     * Display recent activities
     */
    public function activity()
    {
        $activities = Activity::with(['school', 'user'])
            ->latest()
            ->paginate(20)
            ->through(function ($activity) {
                return (object) [
                    'time' => $activity->created_at->format('H:i:s'),
                    'date' => $activity->created_at->format('d M Y'),
                    'school_name' => $activity->school->name ?? 'N/A',
                    'action' => $activity->action,
                    'user_email' => $activity->user->email ?? 'System',
                    'status' => $activity->status ?? 'success',
                    'ip_address' => $activity->ip_address ?? 'N/A',
                ];
            });
        
        $notificationCount = auth()->user()->unreadNotifications()->count();
        $messageCount = auth()->user()->unreadMessages()->count();
        
        return view('super-admin.dashboard.activity', compact(
            'activities',
            'notificationCount',
            'messageCount'
        ));
    }
    
    /**
     * Get system uptime
     */
    private function getSystemUptime(): string
    {
        // Option 1: From cache/database
        $uptimeSeconds = cache()->remember('system_uptime', 60, function () {
            // Calculate uptime from deployment date or last restart
            $deploymentDate = config('app.deployment_date', now()->subDays(15));
            return now()->diffInSeconds($deploymentDate);
        });
        
        // Convert to readable format
        $days = floor($uptimeSeconds / 86400);
        $hours = floor(($uptimeSeconds % 86400) / 3600);
        $minutes = floor(($uptimeSeconds % 3600) / 60);
        
        return sprintf('%dd %dh %dm', $days, $hours, $minutes);
        
        // Option 2: From server (Linux only)
        // $uptime = shell_exec('uptime -p');
        // return trim($uptime);
    }
}
