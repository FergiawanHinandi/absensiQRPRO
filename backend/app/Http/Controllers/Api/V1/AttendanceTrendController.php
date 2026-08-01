<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TrendService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AttendanceTrendController
 *
 * Unified API endpoint for attendance trend charts across all roles.
 * Returns structured data for Recharts visualizations with support
 * for 7-day, 30-day, and 90-day periods.
 *
 * Delegates all query logic to TrendService to eliminate duplication
 * with ExportTrendController and TrendsExport.
 *
 * Cache strategy: 5-minute TTL with school-scoped tags.
 */
class AttendanceTrendController extends Controller
{
    use \App\Traits\UsesCacheTags;

    protected TrendService $trendService;

    public function __construct(TrendService $trendService)
    {
        $this->trendService = $trendService;
    }

    /**
     * GET /api/v1/attendance/trends
     *
     * Returns attendance trend data for charts.
     * Automatically scopes to the user's school.
     * Optionally scopes to teacher's own classes if teacher role.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;
        $period = $request->query('period', '7d');

        // Validate period
        if (!in_array($period, ['7d', '30d', '90d'])) {
            $period = '7d';
        }

        // Teacher scope
        $teacherId = null;
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            $teacherId = $user->id;
        }

        $cacheKey = "attendance_trends_{$schoolId}_{$user->id}_{$period}";
        $cacheTags = ['attendance', "school_{$schoolId}"];

        $data = $this->cacheWithTags($cacheTags, $cacheKey, 300, function () use ($schoolId, $period, $teacherId) {
            $trend = $this->trendService->getDailyTrend($schoolId, $period, $teacherId);

            return [
                'summary' => $trend['summary'],
                'daily' => $trend['daily'],
                'weekly' => $trend['weekly'],
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/attendance/trends/comparison
     *
     * Returns period-over-period comparison (this week vs last week, etc.)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function comparison(Request $request): JsonResponse
    {
        $user = $request->user();
        $schoolId = $user->school_id;

        $teacherId = null;
        if (in_array($user->role_type, ['teacher', 'homeroom_teacher'])) {
            $teacherId = $user->id;
        }

        $cacheKey = "attendance_trends_comparison_{$schoolId}_{$user->id}";
        $cacheTags = ['attendance', "school_{$schoolId}"];

        $data = $this->cacheWithTags($cacheTags, $cacheKey, 300, function () use ($schoolId, $teacherId) {
            return $this->trendService->getComparison($schoolId, $teacherId);
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
