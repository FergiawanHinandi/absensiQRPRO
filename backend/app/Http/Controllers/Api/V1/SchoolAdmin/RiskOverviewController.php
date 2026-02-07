<?php

namespace App\Http\Controllers\Api\V1\SchoolAdmin;

use App\Http\Controllers\Controller;
use App\Services\StudentRiskAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Risk Overview Controller for School Admin
 * Provides student risk analysis and dashboard data
 */
class RiskOverviewController extends Controller
{
    protected StudentRiskAnalysisService $riskService;

    public function __construct(StudentRiskAnalysisService $riskService)
    {
        $this->riskService = $riskService;
    }

    /**
     * Get comprehensive risk overview for school admin dashboard
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = $request->user();
            $schoolId = $user->school_id;

            // Authorization check
            if (! in_array($user->role_type, ['school_admin', 'principal', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized access to risk overview',
                ], 403);
            }

            // Get comprehensive risk overview
            $riskOverview = $this->riskService->getRiskOverview($schoolId);

            return response()->json([
                'success' => true,
                'data' => $riskOverview,
                'message' => 'Risk overview retrieved successfully',
            ]);

        } catch (\Exception $e) {
            \Log::error('Risk Overview Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'school_id' => $request->user()?->school_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve risk overview',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get detailed risk analysis for specific students
     */
    public function studentDetails(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'risk_level' => 'required|in:critical,high,medium,low',
                'class_id' => 'nullable|integer|exists:classes,id',
                'limit' => 'nullable|integer|min:1|max:100',
            ]);

            $user = $request->user();
            $schoolId = $user->school_id;
            $riskLevel = $request->input('risk_level');
            $classId = $request->input('class_id');
            $limit = $request->input('limit', 20);

            // Get detailed student risk data
            $students = $this->riskService->getStudentsByRiskLevel(
                $schoolId,
                $riskLevel,
                $classId,
                $limit
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'students' => $students,
                    'risk_level' => $riskLevel,
                    'class_id' => $classId,
                    'total_count' => count($students),
                ],
                'message' => "Students with {$riskLevel} risk level retrieved successfully",
            ]);

        } catch (\Exception $e) {
            \Log::error('Student Risk Details Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve student risk details',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get risk trend data for charts
     */
    public function trendData(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'days' => 'nullable|integer|min:7|max:90',
            ]);

            $user = $request->user();
            $schoolId = $user->school_id;
            $days = $request->input('days', 30);

            // Get trend data
            $trendData = $this->riskService->getRiskTrendData($schoolId, $days);

            return response()->json([
                'success' => true,
                'data' => [
                    'trend_data' => $trendData,
                    'period_days' => $days,
                    'chart_config' => $this->getChartConfig(),
                ],
                'message' => 'Risk trend data retrieved successfully',
            ]);

        } catch (\Exception $e) {
            \Log::error('Risk Trend Data Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve risk trend data',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Export risk overview data
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'format' => 'required|in:excel,pdf,csv',
                'include_details' => 'nullable|boolean',
            ]);

            $user = $request->user();
            $schoolId = $user->school_id;
            $format = $request->input('format');
            $includeDetails = $request->boolean('include_details', false);

            // Get export data
            $exportData = $this->riskService->getExportData($schoolId, $includeDetails);

            // Generate export file
            $exportService = app(\App\Services\ExportService::class);
            $filePath = $exportService->exportRiskOverview($exportData, $format, $schoolId);

            return response()->json([
                'success' => true,
                'data' => [
                    'download_url' => $filePath,
                    'format' => $format,
                    'generated_at' => now()->toISOString(),
                ],
                'message' => 'Risk overview export generated successfully',
            ]);

        } catch (\Exception $e) {
            \Log::error('Risk Overview Export Error', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()?->id,
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export risk overview',
                'error' => app()->environment('local') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get chart configuration for frontend
     */
    private function getChartConfig(): array
    {
        return [
            'colors' => [
                'critical' => '#dc2626', // red-600
                'high' => '#ea580c',      // orange-600
                'medium' => '#d97706',    // amber-600
                'low' => '#16a34a',       // green-600
            ],
            'labels' => [
                'critical' => 'Kritis',
                'high' => 'Tinggi',
                'medium' => 'Sedang',
                'low' => 'Rendah',
            ],
            'thresholds' => [
                'critical' => StudentRiskAnalysisService::THRESHOLD_CRITICAL,
                'high' => StudentRiskAnalysisService::THRESHOLD_HIGH,
                'medium' => StudentRiskAnalysisService::THRESHOLD_MEDIUM,
            ],
        ];
    }
}
