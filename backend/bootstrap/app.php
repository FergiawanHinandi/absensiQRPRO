<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',

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
            'log.superadmin' => \App\Http\Middleware\LogSuperAdminActivity::class,
            'impersonation' => \App\Http\Middleware\CheckImpersonation::class,
            'ability' => \Laravel\Sanctum\Http\Middleware\CheckForAnyAbility::class,
            'abilities' => \Laravel\Sanctum\Http\Middleware\CheckAbilities::class,
        ]);

        // Add CORS middleware
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
            // \App\Http\Middleware\SecurityHeaders::class,
            // \App\Http\Middleware\StandardizeErrorResponse::class, // Run early to capture timing
            // \App\Http\Middleware\SessionAnomalyDetection::class,
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        // Check user active status on every authenticated request
        $middleware->api(append: [
            \App\Http\Middleware\CheckUserActive::class,
            \App\Http\Middleware\CheckImpersonation::class,
            // \App\Http\Middleware\CheckDeviceReverification::class,
        ]);

        $middleware->preventRequestsDuringMaintenance(except: [
            'api/*',
        ]);

        $middleware->trustProxies(at: [
            '*',
        ], headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $statusCode = 500;
                $response = [
                    'success' => false,
                    'message' => 'Server Error',
                ];

                // Handle specific exceptions
                if ($e instanceof \Illuminate\Validation\ValidationException) {
                    $statusCode = 422;
                    $response['message'] = 'Validation Error';
                    $response['errors'] = $e->errors();
                } elseif ($e instanceof \Illuminate\Auth\AuthenticationException) {
                    $statusCode = 401;
                    $response['message'] = 'Unauthenticated';
                } elseif ($e instanceof \Illuminate\Auth\Access\AuthorizationException || $e instanceof \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException) {
                    $statusCode = 403;
                    $response['message'] = 'Unauthorized';
                } elseif ($e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException || $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
                    $statusCode = 404;
                    $response['message'] = 'Resource Not Found';
                } elseif ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpException) {
                    $statusCode = $e->getStatusCode();
                    $response['message'] = $e->getMessage() ?: 'Error';
                } else {
                    // For 500 errors in debug mode, you might want more info,
                    // but for production, keep it generic or use the exception message if safe.
                    // Here we use the exception message if it's not empty, otherwise Server Error.
                    // Be careful exposing system details in production.
                    $response['message'] = $e->getMessage() ?: 'Server Error';

                    if (config('app.debug')) {
                        $response['trace'] = $e->getTrace();
                    }
                }

                return response()->json($response, $statusCode);
            }
        });
    })->create();
