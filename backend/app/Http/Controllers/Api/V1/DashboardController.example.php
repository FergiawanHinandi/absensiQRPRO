<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Traits\UsesCacheTags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard Controller with Cache Tags
 *
 * Example implementation of event-based cache invalidation
 */
class DashboardController extends Controller
{
    use UsesCacheTags;

    /**
     * Get dashboard metrics
     *
     * Cached with tags: ['dashboard', 'school_{id}']
     * Invalidated when: StudentAttended event fires
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        // Cache key
        $cacheKey = "dashboard_metrics_{$schoolId}";

        // Cache tags
        $cacheTags = ['dashboard', "school_{$schoolId}"];

        // Cache TTL (5 minutes, but will be invalidated on new attendance)
        $cacheTtl = 300; // seconds

        // Get or cache data
        $metrics = $this->cacheWithTags($cacheTags, $cacheKey, $cacheTtl, function () use ($schoolId) {
            return $this->fetchDashboardMetrics($schoolId);
        });

        return response()->json([
            'success' => true,
            'data' => $metrics,
            'cached' => true,
        ]);
    }

    /**
     * Get attendance summary
     */
    public function attendanceSummary(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $period = $request->input('period', 'today'); // today, week, month

        $cacheKey = "attendance_summary_{$schoolId}_{$period}";
        $cacheTags = ['attendance_summary', "school_{$schoolId}"];
        $cacheTtl = 300;

        $summary = $this->cacheWithTags($cacheTags, $cacheKey, $cacheTtl, function () use ($schoolId, $period) {
            return $this->fetchAttendanceSummary($schoolId, $period);
        });

        return response()->json([
            'success' => true,
            'data' => $summary,
        ]);
    }

    /**
     * Get recent attendances
     */
    public function recentAttendances(Request $request)
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $limit = $request->input('limit', 10);

        $cacheKey = "recent_attendances_{$schoolId}_limit_{$limit}";
        $cacheTags = ['dashboard', "school_{$schoolId}"];
        $cacheTtl = 60; // 1 minute for recent data

        $attendances = $this->cacheWithTags($cacheTags, $cacheKey, $cacheTtl, function () use ($schoolId, $limit) {
            return $this->fetchRecentAttendances($schoolId, $limit);
        });

        return response()->json([
            'success' => true,
            'data' => $attendances,
        ]);
    }

    /**
     * Fetch dashboard metrics from database
     */
    protected function fetchDashboardMetrics(int $schoolId): array
    {
        $today = now()->toDateString();

        return [
            'total_students' => DB::table('users')
                ->where('school_id', $schoolId)
                ->where('role_type', 'student')
                ->where('is_active', true)
                ->count(),

            'total_teachers' => DB::table('users')
                ->where('school_id', $schoolId)
                ->whereIn('role_type', ['teacher', 'homeroom_teacher'])
                ->where('is_active', true)
                ->count(),

            'total_classes' => DB::table('classes')
                ->where('school_id', $schoolId)
                ->count(),

            'today_attendance' => DB::table('attendances')
                ->where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->count(),

            'today_present' => DB::table('attendances')
                ->where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->where('status', 'present')
                ->count(),

            'today_late' => DB::table('attendances')
                ->where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->where('status', 'late')
                ->count(),

            'today_absent' => DB::table('attendances')
                ->where('school_id', $schoolId)
                ->where('attendance_date', $today)
                ->where('status', 'absent')
                ->count(),
        ];
    }

    /**
     * Fetch attendance summary from database
     */
    protected function fetchAttendanceSummary(int $schoolId, string $period): array
    {
        $startDate = match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            default => now()->startOfDay(),
        };

        $attendances = DB::table('attendances')
            ->where('school_id', $schoolId)
            ->where('attendance_date', '>=', $startDate->toDateString())
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        $summary = [
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'sick' => 0,
            'permit' => 0,
        ];

        foreach ($attendances as $attendance) {
            $summary[$attendance->status] = $attendance->count;
        }

        return $summary;
    }

    /**
     * Fetch recent attendances from database
     */
    protected function fetchRecentAttendances(int $schoolId, int $limit): array
    {
        return DB::table('attendances')
            ->join('users', 'attendances.student_id', '=', 'users.id')
            ->join('schedules', 'attendances.schedule_id', '=', 'schedules.id')
            ->join('classes', 'schedules.class_id', '=', 'classes.id')
            ->where('attendances.school_id', $schoolId)
            ->select(
                'attendances.id',
                'attendances.status',
                'attendances.check_in_time',
                'attendances.attendance_date',
                'users.name as student_name',
                'classes.name as class_name'
            )
            ->orderBy('attendances.created_at', 'desc')
            ->limit($limit)
            ->get()
            ->toArray();
    }
}
