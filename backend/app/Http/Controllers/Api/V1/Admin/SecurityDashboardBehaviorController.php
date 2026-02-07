<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\BehaviorBaseline;
use App\Models\BehaviorMetricDaily;
use App\Models\User;
use App\Services\BehaviorAnomalyService;
use App\Services\BehaviorBaselineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Security Dashboard Behavior Risk Controller
 *
 * Provides endpoints for viewing teacher behavior risk assessments.
 */
class SecurityDashboardBehaviorController extends Controller
{
    protected BehaviorAnomalyService $anomalyService;

    protected BehaviorBaselineService $baselineService;

    public function __construct(
        BehaviorAnomalyService $anomalyService,
        BehaviorBaselineService $baselineService
    ) {
        $this->anomalyService = $anomalyService;
        $this->baselineService = $baselineService;
    }

    /**
     * GET /api/v1/admin/security-dashboard/behavior-risk
     *
     * Returns teachers with elevated risk levels
     */
    public function behaviorRisk(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $riskLevel = $request->input('risk_level'); // Optional filter: suspicious, high, critical
        $limit = min($request->input('limit', 20), 100);

        $query = BehaviorBaseline::forSchool($schoolId)
            ->with(['user:id,name,email,is_active'])
            ->where('current_risk_score', '>', 0);

        if ($riskLevel) {
            $query->where('current_risk_level', $riskLevel);
        } else {
            $query->atRisk(); // suspicious, high, or critical
        }

        $baselines = $query
            ->orderByRaw("CASE current_risk_level 
                WHEN 'critical' THEN 1 
                WHEN 'high' THEN 2 
                WHEN 'suspicious' THEN 3 
                ELSE 4 END")
            ->orderBy('current_risk_score', 'desc')
            ->limit($limit)
            ->get();

        $results = $baselines->map(function ($baseline) {
            $topFactors = $baseline->getTopRiskFactors(3);

            return [
                'user_id' => $baseline->user_id,
                'teacher_name' => $baseline->user?->name ?? 'Unknown',
                'email' => $baseline->user?->email,
                'is_active' => $baseline->user?->is_active ?? false,
                'risk_level' => $baseline->current_risk_level,
                'risk_color' => BehaviorBaseline::getRiskLevelColor($baseline->current_risk_level),
                'risk_emoji' => BehaviorBaseline::getRiskLevelEmoji($baseline->current_risk_level),
                'score' => $baseline->current_risk_score,
                'top_flags' => array_keys($topFactors),
                'flagged_for_review' => $baseline->flagged_for_review,
                'requires_reverification' => $baseline->requires_device_reverification,
                'last_assessment' => $baseline->last_risk_assessment?->toIso8601String(),
                'baseline_days' => $baseline->days_in_baseline,
            ];
        });

        // Get summary counts
        $summary = [
            'total_at_risk' => BehaviorBaseline::forSchool($schoolId)->atRisk()->count(),
            'critical' => BehaviorBaseline::forSchool($schoolId)->critical()->count(),
            'high' => BehaviorBaseline::forSchool($schoolId)->where('current_risk_level', 'high')->count(),
            'suspicious' => BehaviorBaseline::forSchool($schoolId)->where('current_risk_level', 'suspicious')->count(),
            'flagged_for_review' => BehaviorBaseline::forSchool($schoolId)->flagged()->count(),
            'pending_reverification' => BehaviorBaseline::forSchool($schoolId)->requiresReverification()->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'teachers' => $results,
                'summary' => $summary,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/security-dashboard/behavior-risk/{userId}
     *
     * Get detailed behavior analysis for a specific teacher
     */
    public function teacherDetail(Request $request, int $userId): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        // Verify teacher belongs to admin's school
        $teacher = User::where('id', $userId)
            ->where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->first();

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher not found.',
            ], 404);
        }

        // Get today's analysis
        $analysis = $this->anomalyService->analyzeTodayBehavior($userId);

        // Get baseline
        $baseline = BehaviorBaseline::forUser($userId)->first();

        // Get last 7 days metrics
        $recentMetrics = BehaviorMetricDaily::forUser($userId)
            ->where('date', '>=', now()->subDays(7)->toDateString())
            ->orderBy('date', 'desc')
            ->get()
            ->map(function ($metric) {
                return [
                    'date' => $metric->date->toDateString(),
                    'total_scans' => $metric->total_scans,
                    'failed_scans' => $metric->failed_scans,
                    'failed_ratio' => $metric->failed_ratio,
                    'outside_radius' => $metric->outside_radius_attempts,
                    'device_mismatch' => $metric->device_mismatch_attempts,
                    'avg_scan_interval' => $metric->avg_scan_interval_seconds,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'teacher' => [
                    'id' => $teacher->id,
                    'name' => $teacher->name,
                    'email' => $teacher->email,
                    'is_active' => $teacher->is_active,
                ],
                'current_analysis' => $analysis,
                'baseline' => $baseline ? [
                    'avg_scans_per_day' => $baseline->avg_scans_per_day,
                    'avg_failed_ratio' => $baseline->avg_failed_ratio,
                    'avg_scan_interval' => $baseline->avg_scan_interval_seconds,
                    'days_in_baseline' => $baseline->days_in_baseline,
                    'baseline_period' => [
                        'start' => $baseline->baseline_start_date?->toDateString(),
                        'end' => $baseline->baseline_end_date?->toDateString(),
                    ],
                    'current_risk' => [
                        'level' => $baseline->current_risk_level,
                        'score' => $baseline->current_risk_score,
                        'factors' => $baseline->current_risk_factors,
                    ],
                    'flags' => [
                        'flagged_for_review' => $baseline->flagged_for_review,
                        'flagged_at' => $baseline->flagged_at?->toIso8601String(),
                        'requires_reverification' => $baseline->requires_device_reverification,
                    ],
                ] : null,
                'recent_metrics' => $recentMetrics,
            ],
        ]);
    }

    /**
     * POST /api/v1/admin/security-dashboard/behavior-risk/{userId}/clear-flag
     *
     * Clear review flag for a teacher
     */
    public function clearFlag(Request $request, int $userId): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        $baseline = BehaviorBaseline::forUser($userId)
            ->whereHas('user', fn ($q) => $q->where('school_id', $schoolId))
            ->first();

        if (! $baseline) {
            return response()->json([
                'success' => false,
                'message' => 'Baseline not found.',
            ], 404);
        }

        $baseline->clearFlag();

        return response()->json([
            'success' => true,
            'message' => 'Review flag cleared.',
        ]);
    }

    /**
     * POST /api/v1/admin/security-dashboard/behavior-risk/{userId}/clear-reverification
     *
     * Clear device re-verification requirement
     */
    public function clearReverification(Request $request, int $userId): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        $baseline = BehaviorBaseline::forUser($userId)
            ->whereHas('user', fn ($q) => $q->where('school_id', $schoolId))
            ->first();

        if (! $baseline) {
            return response()->json([
                'success' => false,
                'message' => 'Baseline not found.',
            ], 404);
        }

        $baseline->clearDeviceReverification();

        return response()->json([
            'success' => true,
            'message' => 'Device re-verification requirement cleared.',
        ]);
    }

    /**
     * POST /api/v1/admin/security-dashboard/behavior-risk/{userId}/reanalyze
     *
     * Trigger immediate re-analysis for a teacher
     */
    public function reanalyze(Request $request, int $userId): JsonResponse
    {
        $schoolId = $request->user()->school_id;

        $teacher = User::where('id', $userId)
            ->where('school_id', $schoolId)
            ->where('role_type', 'teacher')
            ->first();

        if (! $teacher) {
            return response()->json([
                'success' => false,
                'message' => 'Teacher not found.',
            ], 404);
        }

        // Recalculate baseline
        $this->baselineService->calculateBaseline($userId);

        // Run anomaly analysis
        $analysis = $this->anomalyService->processAndAlert($userId);

        return response()->json([
            'success' => true,
            'message' => 'Re-analysis completed.',
            'data' => $analysis,
        ]);
    }

    /**
     * GET /api/v1/admin/security-dashboard/behavior-trends
     *
     * Get behavior risk trends over time for the school
     */
    public function behaviorTrends(Request $request): JsonResponse
    {
        $schoolId = $request->user()->school_id;
        $days = min($request->input('days', 14), 30);

        // Get daily aggregated risk data
        $trends = BehaviorMetricDaily::forSchool($schoolId)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('
                date,
                COUNT(DISTINCT user_id) as active_teachers,
                SUM(total_scans) as total_scans,
                SUM(failed_scans) as total_failed,
                SUM(outside_radius_attempts) as total_outside_radius,
                SUM(device_mismatch_attempts) as total_device_mismatch,
                AVG(avg_scan_interval_seconds) as avg_scan_interval
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                return [
                    'date' => $row->date,
                    'active_teachers' => (int) $row->active_teachers,
                    'total_scans' => (int) $row->total_scans,
                    'total_failed' => (int) $row->total_failed,
                    'failed_ratio' => $row->total_scans > 0
                        ? round($row->total_failed / $row->total_scans, 4)
                        : 0,
                    'outside_radius' => (int) $row->total_outside_radius,
                    'device_mismatch' => (int) $row->total_device_mismatch,
                    'avg_scan_interval' => $row->avg_scan_interval
                        ? round($row->avg_scan_interval, 1)
                        : null,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'trends' => $trends,
            ],
        ]);
    }
}
