<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        then: function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_rollback.php'));
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api_monitoring.php'));
            Route::middleware('web')
                ->group(base_path('routes/health.php'));
            Route::middleware('web')
                ->group(base_path('routes/metrics.php'));
            Route::middleware('api')
                ->prefix('api/v1')
                ->group(base_path('routes/api_secure_files.php'));
        },
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'security.headers' => \App\Http\Middleware\SecurityHeaders::class,
            'session.anomaly' => \App\Http\Middleware\SessionAnomalyDetection::class,
            'security.response' => \App\Http\Middleware\StandardizeErrorResponse::class,
            'teacher.device' => \App\Http\Middleware\CheckTeacherDevice::class,
            'behavior.reverification' => \App\Http\Middleware\CheckDeviceReverification::class,
            'log.admin.action' => \App\Http\Middleware\LogAdminAction::class,
            'validate.token.binding' => \App\Http\Middleware\ValidateTokenBinding::class,
            'rate.limit' => \App\Http\Middleware\AdvancedRateLimiting::class,
            'school.rate.limit' => \App\Http\Middleware\RateLimitBySchool::class,
            'attendance.throttle' => \App\Http\Middleware\AttendanceScanThrottle::class,
            'critical.rate.limit' => \App\Http\Middleware\CriticalRateLimiting::class,
            'log.superadmin' => \App\Http\Middleware\LogSuperAdminActivity::class,
            'impersonation' => \App\Http\Middleware\CheckImpersonation::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
            'admin' => \App\Http\Middleware\EnsureUserHasRole::class,
            'validate.multi.tenant.restore' => \App\Http\Middleware\ValidateMultiTenantRestore::class,
            // NEW: Attendance Security Middleware
            'attendance.security' => \App\Http\Middleware\AttendanceSecurityMiddleware::class,
            // NEW: Idempotency Middleware (Replay Attack Prevention)
            'idempotency' => \App\Http\Middleware\IdempotencyMiddleware::class,
            // NEW: Attendance Rate Limiting (Specialized)
            'attendance.rate.limit' => \App\Http\Middleware\AttendanceRateLimitMiddleware::class,
            // ✅ QR Signature Validation (Security Hardened)
            'qr.validate' => \App\Http\Middleware\ValidateQRSignature::class,
            // SECURITY: Device Binding Middleware
            'ensure_device' => \App\Http\Middleware\EnsureDeviceMatch::class,
            // REVENUE PROTECTION: Subscription Check
            'subscription.active' => \App\Http\Middleware\CheckActiveSubscription::class,
            // CONCURRENCY: Deadlock Retry with Exponential Backoff
            'deadlock.retry' => \App\Http\Middleware\DeadlockRetryMiddleware::class,
        ]);

        // Add CORS middleware
        $middleware->api(prepend: [
            \App\Http\Middleware\ObservabilityMiddleware::class, // Metrics collection
            \App\Http\Middleware\LogRequestContext::class, // Centralized request tracing
            \App\Http\Middleware\SecurityHeaders::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            // \App\Http\Middleware\SecurityHeaders::class,
            // \App\Http\Middleware\StandardizeErrorResponse::class, // Run early to capture timing
            // \App\Http\Middleware\SessionAnomalyDetection::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Check user active status on every authenticated request
        $middleware->api(append: [
            \App\Http\Middleware\InputSanitization::class,
            \App\Http\Middleware\CheckUserActive::class,
            \App\Http\Middleware\CheckImpersonation::class,
            // \App\Http\Middleware\CheckDeviceReverification::class,
            \App\Http\Middleware\TenantContextMiddleware::class, // Enterprise: set tenant context
        ]);

        $middleware->web(prepend: [
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->preventRequestsDuringMaintenance(except: [
            'api/*',
        ]);

        $middleware->trustProxies(
            at: [
                '*',
            ],
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Enterprise: Centralized exception mapper (domain exceptions)
        $exceptions->render(function (Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $mapper = app(\App\Infrastructure\Exceptions\ExceptionMapper::class);
                $mapped = $mapper->render($e);
                if ($mapped !== null) {
                    $requestId = \App\Logging\LogContext::get('request_id')
                        ?? $request->header('X-Request-ID')
                        ?? 'unknown';
                    return $mapped->header('X-Request-ID', $requestId);
                }
            }
        });

        $exceptions->render(function (Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                // Get request_id from LogContext or header for correlation
                $requestId = \App\Logging\LogContext::get('request_id') 
                    ?? $request->header('X-Request-ID')
                    ?? 'unknown';
                
                $statusCode = 500;
                // STRICT MOBILE API RESPONSE FORMAT
                $response = [
                    'status' => false, // Replaces 'success'
                    'code' => 500,     // Include code
                    'message' => 'Server Error',
                    'request_id' => $requestId,
                ];

                // Handle specific exceptions
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                    $response['code'] = 422;
                    $response['message'] = 'Validation Error';
                    $response['errors'] = $e->errors();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    // CUSTOM EXPIRED TOKEN RESPONSE
                    $statusCode = 401;
                    $response['code'] = 401;
                    $response['message'] = 'Token expired'; // User demand
                } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException || $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
                    $statusCode = 403;
                    $response['code'] = 403;
                    $response['message'] = 'Unauthorized';
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    $statusCode = 404;
                    $response['code'] = 404;
                    $response['message'] = 'Resource Not Found';
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $statusCode = $e->getStatusCode();
                    $response['code'] = $statusCode;
                    $response['message'] = $e->getMessage() ?: 'Error';
                } else {
                    $response['code'] = 500;
                    $response['message'] = app()->environment('production')
                        ? 'Terjadi kesalahan server. Silakan coba lagi.'
                        : ($e->getMessage() ?: 'Server Error');

                    if (config('app.debug') && !app()->environment('production')) {
                        $response['debug'] = [
                            'exception' => get_class($e),
                            'message' => $e->getMessage(),
                            'file' => basename($e->getFile()),
                            'line' => $e->getLine(),
                        ];
                    }
                }

                // Log exception with full context for debugging
                \Illuminate\Support\Facades\Log::error('Exception handled', [
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'status_code' => $statusCode,
                    'request_id' => $requestId,
                    'user_id' => \App\Logging\LogContext::get('user_id'),
                    'school_id' => \App\Logging\LogContext::get('school_id'),
                    'endpoint' => \App\Logging\LogContext::get('endpoint'),
                ]);

                // Record error to ProductionMonitoringService for metrics
                try {
                    app(\App\Services\ProductionMonitoringService::class)->recordError(
                        get_class($e),
                        $e->getMessage(),
                        [
                            'status_code' => $statusCode,
                            'file' => basename($e->getFile()),
                            'line' => $e->getLine(),
                            'user_id' => \App\Logging\LogContext::get('user_id'),
                            'school_id' => \App\Logging\LogContext::get('school_id'),
                            'endpoint' => \App\Logging\LogContext::get('endpoint'),
                        ]
                    );
                } catch (\Exception $monitoringException) {
                    // Silently fail - monitoring should not break error handling
                }

                return response()->json($response, $statusCode)
                    ->header('X-Request-ID', $requestId);
            }
        });
    })->create();
