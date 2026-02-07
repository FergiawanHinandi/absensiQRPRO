<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TeacherHeatmapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Teacher Heatmap Controller
 *
 * Provides geospatial visualization data for teacher scan locations.
 */
class TeacherHeatmapController extends Controller
{
    public function __construct(
        private TeacherHeatmapService $heatmapService
    ) {}

    /**
     * GET /api/v1/admin/security-dashboard/teacher-heatmap
     *
     * Get clustered heatmap data for visualization
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'teacher_id' => 'nullable|integer|exists:users,id',
            'date' => 'nullable|date',
            'range' => 'nullable|in:7d,14d,30d',
        ]);

        $user = $request->user();
        $schoolId = $this->getSchoolId($user, $request);

        if (! $schoolId) {
            return response()->json([
                'success' => false,
                'message' => 'School ID required',
            ], 400);
        }

        // Validate teacher belongs to school if specified
        $teacherId = $request->input('teacher_id');
        if ($teacherId) {
            $teacher = User::where('id', $teacherId)
                ->where('school_id', $schoolId)
                ->where('role_type', 'teacher')
                ->first();

            if (! $teacher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Teacher not found in this school',
                ], 404);
            }
        }

        $data = $this->heatmapService->getHeatmapData(
            $schoolId,
            $teacherId,
            $request->input('date'),
            $request->input('range')
        );

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * GET /api/v1/admin/security-dashboard/teacher-heatmap/cluster-details
     *
     * Get detailed information for a specific cluster location
     */
    public function clusterDetails(Request $request): JsonResponse
    {
        $request->validate([
            'lat' => 'required|numeric',
            'lng' => 'required|numeric',
            'date' => 'nullable|date',
            'range' => 'nullable|in:7d,14d,30d',
        ]);

        $user = $request->user();
        $schoolId = $this->getSchoolId($user, $request);

        if (! $schoolId) {
            return response()->json([
                'success' => false,
                'message' => 'School ID required',
            ], 400);
        }

        $details = $this->heatmapService->getClusterDetails(
            $schoolId,
            (float) $request->input('lat'),
            (float) $request->input('lng'),
            $request->input('date'),
            $request->input('range')
        );

        return response()->json([
            'success' => true,
            'data' => $details,
        ]);
    }

    /**
     * GET /api/v1/admin/security-dashboard/teacher-heatmap/teachers
     *
     * Get list of teachers with scan summaries for filtering
     */
    public function teacherSummary(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'range' => 'nullable|in:7d,14d,30d',
        ]);

        $user = $request->user();
        $schoolId = $this->getSchoolId($user, $request);

        if (! $schoolId) {
            return response()->json([
                'success' => false,
                'message' => 'School ID required',
            ], 400);
        }

        $summary = $this->heatmapService->getTeacherScanSummary(
            $schoolId,
            $request->input('date'),
            $request->input('range')
        );

        return response()->json([
            'success' => true,
            'data' => [
                'teachers' => $summary,
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/security-dashboard/teacher-heatmap/anomalies
     *
     * Get scans that occurred outside the allowed zone
     */
    public function anomalies(Request $request): JsonResponse
    {
        $request->validate([
            'date' => 'nullable|date',
            'range' => 'nullable|in:7d,14d,30d',
        ]);

        $user = $request->user();
        $schoolId = $this->getSchoolId($user, $request);

        if (! $schoolId) {
            return response()->json([
                'success' => false,
                'message' => 'School ID required',
            ], 400);
        }

        $data = $this->heatmapService->getHeatmapData(
            $schoolId,
            null,
            $request->input('date'),
            $request->input('range')
        );

        // Filter only outside zone clusters
        $anomalies = collect($data['points'])
            ->filter(fn ($p) => $p['outside_zone'] ?? false)
            ->values()
            ->all();

        return response()->json([
            'success' => true,
            'data' => [
                'anomalies' => $anomalies,
                'school' => $data['school'],
                'stats' => [
                    'total_outside_zone' => count($anomalies),
                    'affected_teachers' => collect($anomalies)
                        ->pluck('teacher_ids')
                        ->flatten()
                        ->unique()
                        ->count(),
                ],
            ],
        ]);
    }

    /**
     * Get school ID based on user role
     */
    private function getSchoolId(User $user, Request $request): ?int
    {
        // Super admin can specify school_id
        if ($user->role_type === 'super_admin') {
            $schoolId = $request->input('school_id');
            if ($schoolId) {
                return (int) $schoolId;
            }

            // If no school_id specified, return null (super admin must specify)
            return null;
        }

        // School admin uses their own school
        return $user->school_id;
    }
}
