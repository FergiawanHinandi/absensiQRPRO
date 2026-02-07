<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SecurityReport;
use App\Models\User;
use App\Services\TeacherSecurityReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SecurityReportController extends Controller
{
    protected TeacherSecurityReportService $reportService;

    public function __construct(TeacherSecurityReportService $reportService)
    {
        $this->reportService = $reportService;
    }

    /**
     * Generate a new security investigation report for a teacher
     *
     * POST /api/v1/admin/security-reports/teacher
     */
    public function generateTeacherReport(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'teacher_id' => ['required', 'integer', 'exists:users,id'],
            'range' => ['sometimes', 'string', Rule::in(['7d', '14d', '30d'])],
        ]);

        $teacherId = $validated['teacher_id'];
        $range = $validated['range'] ?? '7d';

        // Get the teacher and verify access
        $teacher = User::with('school')->findOrFail($teacherId);

        // Authorization: super_admin or school_admin of same school
        $user = $request->user();

        if (! $this->canAccessTeacher($user, $teacher)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: You can only generate reports for teachers in your school.',
            ], 403);
        }

        // Verify teacher is actually a teacher
        if (! in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            return response()->json([
                'success' => false,
                'message' => 'The specified user is not a teacher.',
            ], 422);
        }

        try {
            $report = $this->reportService->generateReport(
                $teacherId,
                $range,
                $user->id,
                SecurityReport::GENERATION_MANUAL
            );

            return response()->json([
                'success' => true,
                'message' => 'Security report generated successfully.',
                'data' => [
                    'report_id' => $report->id,
                    'teacher_name' => $teacher->name,
                    'risk_level' => $report->risk_level,
                    'file_name' => $report->file_name,
                    'download_url' => route('admin.security-reports.download', $report->id),
                    'created_at' => $report->created_at->toIso8601String(),
                    'summary' => $report->summary_data,
                ],
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate report: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Download a security report
     *
     * GET /api/v1/admin/security-reports/{id}/download
     */
    public function download(Request $request, int $id): JsonResponse|StreamedResponse
    {
        $report = SecurityReport::with(['teacher', 'generator'])->findOrFail($id);
        $user = $request->user();

        // Authorization: only creator or super_admin can download
        if (! $this->canDownloadReport($user, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized: Only the report creator or super admin can download this report.',
            ], 403);
        }

        // Check if file exists
        if (! $report->fileExists()) {
            return response()->json([
                'success' => false,
                'message' => 'Report file not found on server.',
            ], 404);
        }

        return Storage::download(
            $report->file_path,
            $report->file_name,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$report->file_name.'"',
            ]
        );
    }

    /**
     * List security reports with filters
     *
     * GET /api/v1/admin/security-reports
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'teacher_id' => ['sometimes', 'integer'],
            'school_id' => ['sometimes', 'integer'],
            'risk_level' => ['sometimes', 'string', Rule::in(['low', 'medium', 'high', 'critical'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $user = $request->user();
        $query = SecurityReport::with(['teacher:id,name,email', 'generator:id,name', 'school:id,name']);

        // Scope by school for non-super admins
        if ($user->role_type !== 'super_admin') {
            $query->where('school_id', $user->school_id);
        } elseif (isset($validated['school_id'])) {
            $query->where('school_id', $validated['school_id']);
        }

        // Apply filters
        if (isset($validated['teacher_id'])) {
            $query->where('teacher_id', $validated['teacher_id']);
        }

        if (isset($validated['risk_level'])) {
            $query->where('risk_level', $validated['risk_level']);
        }

        $reports = $query->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'success' => true,
            'data' => [
                'reports' => $reports->items(),
                'pagination' => [
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                    'per_page' => $reports->perPage(),
                    'total' => $reports->total(),
                ],
            ],
        ]);
    }

    /**
     * Get a single report details
     *
     * GET /api/v1/admin/security-reports/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $report = SecurityReport::with(['teacher', 'generator', 'school'])->findOrFail($id);
        $user = $request->user();

        if (! $this->canDownloadReport($user, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this report.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $report->id,
                'teacher' => [
                    'id' => $report->teacher->id,
                    'name' => $report->teacher->name,
                    'email' => $report->teacher->email,
                ],
                'school' => [
                    'id' => $report->school->id,
                    'name' => $report->school->name,
                ],
                'generated_by' => [
                    'id' => $report->generator->id,
                    'name' => $report->generator->name,
                ],
                'risk_level' => $report->risk_level,
                'risk_label' => $report->risk_label,
                'risk_color' => $report->risk_color,
                'date_range' => $report->date_range,
                'generation_type' => $report->generation_type,
                'file_name' => $report->file_name,
                'file_exists' => $report->fileExists(),
                'summary' => $report->summary_data,
                'report_period' => [
                    'start' => $report->report_period_start?->toIso8601String(),
                    'end' => $report->report_period_end?->toIso8601String(),
                ],
                'created_at' => $report->created_at->toIso8601String(),
                'download_url' => route('admin.security-reports.download', $report->id),
            ],
        ]);
    }

    /**
     * Delete a security report
     *
     * DELETE /api/v1/admin/security-reports/{id}
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $report = SecurityReport::findOrFail($id);
        $user = $request->user();

        // Only super_admin or the creator can delete
        if (! $this->canDownloadReport($user, $report)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete this report.',
            ], 403);
        }

        // Delete the file
        if ($report->fileExists()) {
            Storage::delete($report->file_path);
        }

        $report->delete();

        return response()->json([
            'success' => true,
            'message' => 'Security report deleted successfully.',
        ]);
    }

    /**
     * Check if user can access a teacher's data
     */
    protected function canAccessTeacher(User $user, User $teacher): bool
    {
        if ($user->role_type === 'super_admin') {
            return true;
        }

        if (in_array($user->role_type, ['admin', 'school_admin'])) {
            return $user->school_id === $teacher->school_id;
        }

        return false;
    }

    /**
     * Check if user can download/view a report
     */
    protected function canDownloadReport(User $user, SecurityReport $report): bool
    {
        if ($user->role_type === 'super_admin') {
            return true;
        }

        // Creator can always access their own reports
        if ($report->generated_by === $user->id) {
            return true;
        }

        // School admin can access reports from their school
        if (in_array($user->role_type, ['admin', 'school_admin'])) {
            return $user->school_id === $report->school_id;
        }

        return false;
    }
}
