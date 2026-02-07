<?php

namespace App\Http\Middleware;

use App\Models\AdminActivityLog;
use App\Services\AdminAuditService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Log Admin Action Middleware
 *
 * Automatically captures and logs admin actions for audit purposes.
 * This middleware should be applied to admin routes to track all
 * POST, PUT, PATCH, and DELETE operations.
 *
 * The middleware intelligently determines the action type based on:
 * - Route name patterns
 * - HTTP method
 * - Request path
 *
 * For more specific logging, use AdminAuditService directly in controllers.
 */
class LogAdminAction
{
    protected AdminAuditService $auditService;

    /**
     * HTTP methods that should be logged (state-changing operations)
     */
    protected const LOGGED_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Route patterns that should be excluded from automatic logging
     * (typically already logged explicitly or too noisy)
     */
    protected const EXCLUDED_ROUTES = [
        'admin.notifications.*',
        'admin.activity.*',  // Don't log viewing activity logs again
        'sanctum.*',
    ];

    /**
     * Route name to action type mapping
     */
    protected const ROUTE_ACTION_MAP = [
        // User Management
        'admin.users.store' => AdminActivityLog::ACTION_USER_CREATE,
        'admin.users.update' => AdminActivityLog::ACTION_USER_UPDATE,
        'admin.users.destroy' => AdminActivityLog::ACTION_USER_DELETE,
        'admin.users.activate' => AdminActivityLog::ACTION_USER_ACTIVATE,
        'admin.users.deactivate' => AdminActivityLog::ACTION_USER_DEACTIVATE,
        'admin.users.reset-password' => AdminActivityLog::ACTION_USER_PASSWORD_RESET,

        // Teacher Management
        'admin.teachers.store' => AdminActivityLog::ACTION_TEACHER_CREATE,
        'admin.teachers.update' => AdminActivityLog::ACTION_TEACHER_UPDATE,
        'admin.teachers.destroy' => AdminActivityLog::ACTION_TEACHER_DELETE,
        'admin.teachers.device-reset' => AdminActivityLog::ACTION_TEACHER_DEVICE_RESET,
        'admin.teachers.assign-schedule' => AdminActivityLog::ACTION_TEACHER_SCHEDULE_ASSIGN,

        // Student Management
        'admin.students.store' => AdminActivityLog::ACTION_STUDENT_CREATE,
        'admin.students.update' => AdminActivityLog::ACTION_STUDENT_UPDATE,
        'admin.students.destroy' => AdminActivityLog::ACTION_STUDENT_DELETE,
        'admin.students.import' => AdminActivityLog::ACTION_STUDENT_IMPORT,
        'admin.students.regenerate-qr' => AdminActivityLog::ACTION_STUDENT_QR_REGENERATE,

        // Class Management
        'admin.classes.store' => AdminActivityLog::ACTION_CLASS_CREATE,
        'admin.classes.update' => AdminActivityLog::ACTION_CLASS_UPDATE,
        'admin.classes.destroy' => AdminActivityLog::ACTION_CLASS_DELETE,

        // Subject Management
        'admin.subjects.store' => AdminActivityLog::ACTION_SUBJECT_CREATE,
        'admin.subjects.update' => AdminActivityLog::ACTION_SUBJECT_UPDATE,
        'admin.subjects.destroy' => AdminActivityLog::ACTION_SUBJECT_DELETE,

        // Schedule Management
        'admin.schedules.store' => AdminActivityLog::ACTION_SCHEDULE_CREATE,
        'admin.schedules.update' => AdminActivityLog::ACTION_SCHEDULE_UPDATE,
        'admin.schedules.destroy' => AdminActivityLog::ACTION_SCHEDULE_DELETE,
        'admin.schedules.bulk-store' => AdminActivityLog::ACTION_SCHEDULE_BULK_CREATE,

        // Attendance Management
        'admin.attendance.override' => AdminActivityLog::ACTION_ATTENDANCE_OVERRIDE,
        'admin.attendance.manual-entry' => AdminActivityLog::ACTION_ATTENDANCE_MANUAL_ENTRY,
        'admin.attendance.destroy' => AdminActivityLog::ACTION_ATTENDANCE_DELETE,
        'admin.attendance.export' => AdminActivityLog::ACTION_ATTENDANCE_EXPORT,

        // School Management
        'admin.schools.store' => AdminActivityLog::ACTION_SCHOOL_CREATE,
        'admin.schools.update' => AdminActivityLog::ACTION_SCHOOL_UPDATE,
        'admin.schools.destroy' => AdminActivityLog::ACTION_SCHOOL_DELETE,
        'admin.schools.settings' => AdminActivityLog::ACTION_SCHOOL_SETTINGS_UPDATE,
        'admin.schools.geofence' => AdminActivityLog::ACTION_GEOFENCE_UPDATE,

        // System Management
        'admin.backup.create' => AdminActivityLog::ACTION_BACKUP_CREATE,
        'admin.backup.restore' => AdminActivityLog::ACTION_BACKUP_RESTORE,
        'admin.maintenance.toggle' => AdminActivityLog::ACTION_MAINTENANCE_ENABLE,

        // Data Export/Import
        'admin.export.*' => AdminActivityLog::ACTION_DATA_EXPORT,
        'admin.import.*' => AdminActivityLog::ACTION_DATA_IMPORT,
        'admin.reports.generate' => AdminActivityLog::ACTION_REPORT_GENERATE,
    ];

    public function __construct(AdminAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Process the request first
        $response = $next($request);

        // Only log state-changing operations
        if (! in_array($request->method(), self::LOGGED_METHODS)) {
            return $response;
        }

        // Only log for authenticated admin users
        $user = Auth::user();
        if (! $user || ! $this->isAdminUser($user)) {
            return $response;
        }

        // Skip excluded routes
        $routeName = $request->route()?->getName();
        if ($this->isExcludedRoute($routeName)) {
            return $response;
        }

        // Only log successful operations (2xx responses)
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return $response;
        }

        try {
            $this->logAction($request, $response, $routeName);
        } catch (\Exception $e) {
            // Log but don't fail the request
            Log::channel('security')->error('LogAdminAction middleware failed', [
                'route' => $routeName,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    /**
     * Check if the user is an admin.
     */
    protected function isAdminUser($user): bool
    {
        return in_array($user->role_type ?? '', ['super_admin', 'school_admin', 'admin']);
    }

    /**
     * Check if the route should be excluded from logging.
     */
    protected function isExcludedRoute(?string $routeName): bool
    {
        if (! $routeName) {
            return false;
        }

        foreach (self::EXCLUDED_ROUTES as $pattern) {
            if ($this->routeMatchesPattern($routeName, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log the admin action.
     */
    protected function logAction(Request $request, Response $response, ?string $routeName): void
    {
        $actionType = $this->resolveActionType($request, $routeName);
        $targetInfo = $this->resolveTarget($request);
        $description = $this->generateDescription($request, $actionType, $targetInfo);

        $this->auditService->log(
            $actionType,
            $targetInfo['type'],
            $targetInfo['id'],
            $description,
            $this->buildMetadata($request, $response)
        );
    }

    /**
     * Resolve the action type from route name or request.
     */
    protected function resolveActionType(Request $request, ?string $routeName): string
    {
        // Try exact route match first
        if ($routeName && isset(self::ROUTE_ACTION_MAP[$routeName])) {
            return self::ROUTE_ACTION_MAP[$routeName];
        }

        // Try pattern matching
        if ($routeName) {
            foreach (self::ROUTE_ACTION_MAP as $pattern => $actionType) {
                if ($this->routeMatchesPattern($routeName, $pattern)) {
                    return $actionType;
                }
            }
        }

        // Fall back to inferring from HTTP method and path
        return $this->inferActionType($request);
    }

    /**
     * Check if a route name matches a pattern (supports wildcards).
     */
    protected function routeMatchesPattern(string $routeName, string $pattern): bool
    {
        if ($routeName === $pattern) {
            return true;
        }

        // Convert wildcard pattern to regex
        $regex = '/^'.str_replace(['.', '*'], ['\.', '.*'], $pattern).'$/';

        return (bool) preg_match($regex, $routeName);
    }

    /**
     * Infer action type from HTTP method and path.
     */
    protected function inferActionType(Request $request): string
    {
        $method = $request->method();
        $path = $request->path();

        // Extract the resource name from path
        $segments = explode('/', $path);
        $resource = $this->guessResourceFromPath($segments);

        return match ($method) {
            'POST' => "{$resource}_create",
            'PUT', 'PATCH' => "{$resource}_update",
            'DELETE' => "{$resource}_delete",
            default => "{$resource}_action",
        };
    }

    /**
     * Guess the resource type from path segments.
     */
    protected function guessResourceFromPath(array $segments): string
    {
        // Look for common resource patterns
        $resourceKeywords = [
            'teachers' => 'teacher',
            'students' => 'student',
            'users' => 'user',
            'schools' => 'school',
            'classes' => 'class',
            'subjects' => 'subject',
            'schedules' => 'schedule',
            'attendance' => 'attendance',
            'reports' => 'report',
            'settings' => 'settings',
            'backup' => 'backup',
        ];

        foreach ($segments as $segment) {
            $lower = strtolower($segment);
            if (isset($resourceKeywords[$lower])) {
                return $resourceKeywords[$lower];
            }
        }

        return 'resource';
    }

    /**
     * Resolve the target entity from the request.
     */
    protected function resolveTarget(Request $request): array
    {
        $route = $request->route();

        // Try to get target from route parameters
        $parameters = $route?->parameters() ?? [];

        // Common parameter names for resource IDs
        $idParams = ['id', 'user', 'teacher', 'student', 'school', 'class', 'subject', 'schedule', 'attendance'];

        foreach ($idParams as $param) {
            if (isset($parameters[$param])) {
                $value = $parameters[$param];
                $id = is_object($value) && method_exists($value, 'getKey')
                    ? $value->getKey()
                    : (is_numeric($value) ? (int) $value : null);

                return [
                    'type' => ucfirst($param === 'id' ? $this->guessResourceFromPath(explode('/', $request->path())) : $param),
                    'id' => $id,
                ];
            }
        }

        // Try to get from request body for creates
        if ($request->method() === 'POST') {
            return [
                'type' => ucfirst($this->guessResourceFromPath(explode('/', $request->path()))),
                'id' => $request->input('id'),
            ];
        }

        return ['type' => null, 'id' => null];
    }

    /**
     * Generate a human-readable description.
     */
    protected function generateDescription(Request $request, string $actionType, array $targetInfo): string
    {
        $method = $request->method();
        $actionDisplay = ucwords(str_replace('_', ' ', $actionType));

        $targetDesc = '';
        if ($targetInfo['type'] && $targetInfo['id']) {
            $targetDesc = " on {$targetInfo['type']} #{$targetInfo['id']}";
        } elseif ($targetInfo['type']) {
            $targetDesc = " on {$targetInfo['type']}";
        }

        return "{$actionDisplay}{$targetDesc} via {$method} {$request->path()}";
    }

    /**
     * Build metadata from request and response.
     */
    protected function buildMetadata(Request $request, Response $response): array
    {
        $metadata = [
            'http_status' => $response->getStatusCode(),
            'route_parameters' => $request->route()?->parameters() ?? [],
        ];

        // Include request body for creates/updates, excluding sensitive fields
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $sensitiveFields = ['password', 'password_confirmation', 'current_password', 'secret', 'token', 'api_key'];
            $input = $request->except($sensitiveFields);

            // Don't include large payloads
            $encoded = json_encode($input);
            if ($encoded !== false && strlen($encoded) < 5000) {
                $metadata['request_body'] = $input;
            }
        }

        // Try to extract created/updated ID from response
        if ($response->getStatusCode() === 201) {
            $content = $response->getContent();
            if ($content) {
                $decoded = json_decode($content, true);
                if (isset($decoded['data']['id'])) {
                    $metadata['created_id'] = $decoded['data']['id'];
                } elseif (isset($decoded['id'])) {
                    $metadata['created_id'] = $decoded['id'];
                }
            }
        }

        return $metadata;
    }
}
