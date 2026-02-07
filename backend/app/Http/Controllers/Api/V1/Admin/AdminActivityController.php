<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminActivityLog;
use App\Services\AdminAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin Activity Controller
 *
 * Provides endpoints for viewing and filtering admin activity logs.
 * Access is role-based:
 * - super_admin: Can view all activity across all schools
 * - school_admin: Can view activity within their school
 */
class AdminActivityController extends Controller
{
    protected AdminAuditService $auditService;

    public function __construct(AdminAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * List admin activity logs with filtering and pagination.
     *
     * GET /api/v1/admin/system/admin-activity
     *
     * Query Parameters:
     * - admin_user_id: Filter by specific admin
     * - action_type: Filter by action type
     * - target_type: Filter by target entity type
     * - high_risk: boolean - Show only high-risk actions
     * - date_from: Filter from date (Y-m-d)
     * - date_to: Filter to date (Y-m-d)
     * - search: Search in description
     * - per_page: Pagination size (default: 25, max: 100)
     */
    public function index(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Log that this admin is viewing activity logs
        $this->auditService->logAuditLogView(
            'admin_activity',
            $request->only(['admin_user_id', 'action_type', 'target_type', 'high_risk', 'date_from', 'date_to'])
        );

        // Build the query
        $query = AdminActivityLog::query()
            ->with(['adminUser:id,name,email,role_type', 'school:id,name'])
            ->orderByDesc('created_at');

        // School-level admins can only see their school's activity
        if ($user->role_type !== 'super_admin') {
            $query->where('school_id', $user->school_id);
        } elseif ($request->filled('school_id')) {
            // Super admins can filter by school
            $query->where('school_id', $request->input('school_id'));
        }

        // Apply filters
        if ($request->filled('admin_user_id')) {
            $query->forAdmin((int) $request->input('admin_user_id'));
        }

        if ($request->filled('action_type')) {
            $query->ofType($request->input('action_type'));
        }

        if ($request->filled('action_types')) {
            $types = is_array($request->input('action_types'))
                ? $request->input('action_types')
                : explode(',', $request->input('action_types'));
            $query->ofTypes($types);
        }

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->input('target_type'));
        }

        if ($request->filled('target_id')) {
            $query->where('target_id', (int) $request->input('target_id'));
        }

        if ($request->boolean('high_risk')) {
            $query->highRisk();
        }

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->input('date_from').' 00:00:00');
        }

        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->input('date_to').' 23:59:59');
        }

        if ($request->filled('search')) {
            $query->search($request->input('search'));
        }

        // Pagination
        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $activities = $query->paginate($perPage);

        // Transform the data
        $data = $activities->through(function ($log) {
            return [
                'id' => $log->id,
                'admin' => $log->adminUser ? [
                    'id' => $log->adminUser->id,
                    'name' => $log->adminUser->name,
                    'email' => $log->adminUser->email,
                ] : null,
                'role' => $log->role,
                'school' => $log->school ? [
                    'id' => $log->school->id,
                    'name' => $log->school->name,
                ] : null,
                'action_type' => $log->action_type,
                'action_display' => $log->getActionDisplayName(),
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'description' => $log->description,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'is_high_risk' => $log->isHighRisk(),
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data->items(),
            'meta' => [
                'current_page' => $activities->currentPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'last_page' => $activities->lastPage(),
            ],
        ]);
    }

    /**
     * Get a single activity log entry.
     *
     * GET /api/v1/admin/system/admin-activity/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = Auth::user();

        $query = AdminActivityLog::query()
            ->with(['adminUser:id,name,email,role_type', 'school:id,name']);

        // School-level admins can only see their school's activity
        if ($user->role_type !== 'super_admin') {
            $query->where('school_id', $user->school_id);
        }

        $log = $query->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $log->id,
                'admin' => $log->adminUser ? [
                    'id' => $log->adminUser->id,
                    'name' => $log->adminUser->name,
                    'email' => $log->adminUser->email,
                    'role_type' => $log->adminUser->role_type,
                ] : null,
                'role' => $log->role,
                'school' => $log->school ? [
                    'id' => $log->school->id,
                    'name' => $log->school->name,
                ] : null,
                'action_type' => $log->action_type,
                'action_display' => $log->getActionDisplayName(),
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'description' => $log->description,
                'metadata' => $log->metadata,
                'ip_address' => $log->ip_address,
                'user_agent' => $log->user_agent,
                'route_name' => $log->route_name,
                'http_method' => $log->http_method,
                'is_high_risk' => $log->isHighRisk(),
                'created_at' => $log->created_at->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get summary statistics for admin activity.
     *
     * GET /api/v1/admin/system/admin-activity/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $user = Auth::user();
        $days = (int) ($request->input('days', 7));
        $days = min(max($days, 1), 90); // Clamp between 1 and 90 days

        $since = now()->subDays($days);

        // Build base query with school scoping
        $baseQuery = AdminActivityLog::query()->where('created_at', '>=', $since);

        if ($user->role_type !== 'super_admin') {
            $baseQuery->where('school_id', $user->school_id);
        } elseif ($request->filled('school_id')) {
            $baseQuery->where('school_id', $request->input('school_id'));
        }

        // Total actions count
        $totalActions = (clone $baseQuery)->count();

        // High-risk actions count
        $highRiskCount = (clone $baseQuery)->highRisk()->count();

        // Actions by type
        $actionsByType = (clone $baseQuery)
            ->selectRaw('action_type, COUNT(*) as count')
            ->groupBy('action_type')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'action_type')
            ->toArray();

        // Most active admins
        $mostActiveAdmins = (clone $baseQuery)
            ->selectRaw('admin_user_id, COUNT(*) as action_count')
            ->groupBy('admin_user_id')
            ->orderByDesc('action_count')
            ->limit(5)
            ->with('adminUser:id,name,email')
            ->get()
            ->map(function ($item) {
                return [
                    'admin' => $item->adminUser ? [
                        'id' => $item->adminUser->id,
                        'name' => $item->adminUser->name,
                        'email' => $item->adminUser->email,
                    ] : null,
                    'action_count' => $item->action_count,
                ];
            });

        // Recent high-risk actions
        $recentHighRisk = (clone $baseQuery)
            ->highRisk()
            ->with(['adminUser:id,name,email'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get()
            ->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action_type' => $log->action_type,
                    'action_display' => $log->getActionDisplayName(),
                    'admin' => $log->adminUser ? [
                        'name' => $log->adminUser->name,
                        'email' => $log->adminUser->email,
                    ] : null,
                    'description' => $log->description,
                    'created_at' => $log->created_at->toIso8601String(),
                ];
            });

        // Daily activity trend
        $dailyTrend = (clone $baseQuery)
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();

        return response()->json([
            'success' => true,
            'data' => [
                'period_days' => $days,
                'total_actions' => $totalActions,
                'high_risk_count' => $highRiskCount,
                'high_risk_percentage' => $totalActions > 0
                    ? round(($highRiskCount / $totalActions) * 100, 1)
                    : 0,
                'actions_by_type' => $actionsByType,
                'most_active_admins' => $mostActiveAdmins,
                'recent_high_risk' => $recentHighRisk,
                'daily_trend' => $dailyTrend,
            ],
        ]);
    }

    /**
     * Get available action types for filtering.
     *
     * GET /api/v1/admin/system/admin-activity/action-types
     */
    public function actionTypes(): JsonResponse
    {
        $types = AdminActivityLog::getAllActionTypes();

        $formatted = collect($types)->map(function ($value, $key) {
            return [
                'value' => $value,
                'label' => ucwords(str_replace('_', ' ', str_replace('ACTION_', '', $key))),
                'is_high_risk' => in_array($value, AdminActivityLog::HIGH_RISK_ACTIONS),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $formatted,
        ]);
    }

    /**
     * Get activity for a specific target entity.
     *
     * GET /api/v1/admin/system/admin-activity/target/{type}/{id}
     */
    public function forTarget(Request $request, string $type, int $id): JsonResponse
    {
        $user = Auth::user();

        $query = AdminActivityLog::query()
            ->forTarget(ucfirst($type), $id)
            ->with(['adminUser:id,name,email'])
            ->orderByDesc('created_at');

        // School scoping
        if ($user->role_type !== 'super_admin') {
            $query->where('school_id', $user->school_id);
        }

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $activities = $query->paginate($perPage);

        $data = $activities->through(function ($log) {
            return [
                'id' => $log->id,
                'admin' => $log->adminUser ? [
                    'name' => $log->adminUser->name,
                    'email' => $log->adminUser->email,
                ] : null,
                'action_type' => $log->action_type,
                'action_display' => $log->getActionDisplayName(),
                'description' => $log->description,
                'is_high_risk' => $log->isHighRisk(),
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $data->items(),
            'meta' => [
                'target_type' => ucfirst($type),
                'target_id' => $id,
                'current_page' => $activities->currentPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'last_page' => $activities->lastPage(),
            ],
        ]);
    }

    /**
     * Get activity for the current admin user.
     *
     * GET /api/v1/admin/system/admin-activity/my-activity
     */
    public function myActivity(Request $request): JsonResponse
    {
        $user = Auth::user();

        $query = AdminActivityLog::query()
            ->forAdmin($user->id)
            ->orderByDesc('created_at');

        $perPage = min((int) ($request->input('per_page', 25)), 100);
        $activities = $query->paginate($perPage);

        $data = $activities->through(function ($log) {
            return [
                'id' => $log->id,
                'action_type' => $log->action_type,
                'action_display' => $log->getActionDisplayName(),
                'target_type' => $log->target_type,
                'target_id' => $log->target_id,
                'description' => $log->description,
                'is_high_risk' => $log->isHighRisk(),
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        // Get summary
        $summary = $this->auditService->getAdminActivitySummary($user->id, 30);

        return response()->json([
            'success' => true,
            'data' => $data->items(),
            'summary' => $summary,
            'meta' => [
                'current_page' => $activities->currentPage(),
                'per_page' => $activities->perPage(),
                'total' => $activities->total(),
                'last_page' => $activities->lastPage(),
            ],
        ]);
    }
}
