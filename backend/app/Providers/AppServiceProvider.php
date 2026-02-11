<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Enterprise domain architecture
        $this->app->register(DomainServiceProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // CRITICAL: Security checks - prevent production incidents
        $this->enforceProductionSecurity();
        $this->checkDefaultSecrets();

        // Use custom PersonalAccessToken model with security fields
        \Laravel\Sanctum\Sanctum::usePersonalAccessTokenModel(\App\Models\PersonalAccessToken::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Register model observers
        \App\Models\School::observe(\App\Observers\SchoolObserver::class);
        \App\Models\User::observe(\App\Observers\UserObserver::class);
        
        // ✅ OPTIMIZATION: Auto-invalidate cache when attendance changes
        \App\Models\Attendance::observe(\App\Observers\AttendanceObserver::class);

        // Enforce strict mode in development to prevent N+1 and attribute errors
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(! $this->app->isProduction());
        \Illuminate\Database\Eloquent\Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        \Illuminate\Database\Eloquent\Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // Global queue failure handler for production monitoring
        // CRITICAL: This logs all queue job failures for incident response
        Queue::failing(function (JobFailed $event) {
            Log::channel('security')->error('Queue Job Failed', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job_name' => $event->job->resolveName(),
                'job_id' => $event->job->getJobId(),
                'payload' => $event->job->payload(),
                'exception_message' => $event->exception->getMessage(),
                'exception_trace' => $event->exception->getTraceAsString(),
                'failed_at' => now()->toIso8601String(),
            ]);
        });

        // ============================================================
        // SLOW QUERY LISTENER - Enhanced Monitoring
        // ============================================================
        // Logs queries taking longer than 500ms for performance optimization
        // 
        // FEATURES:
        // - SQL query with bindings
        // - Execution time in milliseconds
        // - Request context (URL, method, user)
        // - Stack trace for debugging
        // - Query type detection (SELECT, INSERT, UPDATE, DELETE)
        // 
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            $threshold = config('database.slow_query_threshold', 500); // Default 500ms
            
            if ($query->time > $threshold) {
                // Detect query type
                $sql = strtoupper(trim($query->sql));
                $queryType = 'UNKNOWN';
                if (str_starts_with($sql, 'SELECT')) {
                    $queryType = 'SELECT';
                } elseif (str_starts_with($sql, 'INSERT')) {
                    $queryType = 'INSERT';
                } elseif (str_starts_with($sql, 'UPDATE')) {
                    $queryType = 'UPDATE';
                } elseif (str_starts_with($sql, 'DELETE')) {
                    $queryType = 'DELETE';
                }
                
                // Get user context if available
                $user = request()->user();
                $userContext = $user ? [
                    'user_id' => $user->id,
                    'user_role' => $user->role ?? 'unknown',
                    'school_id' => $user->school_id ?? null,
                ] : null;
                
                // Get stack trace (limited to avoid log bloat)
                $trace = collect(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10))
                    ->filter(function ($item) {
                        // Filter out framework internals
                        return isset($item['file']) && 
                               !str_contains($item['file'], 'vendor/laravel') &&
                               !str_contains($item['file'], 'vendor/illuminate');
                    })
                    ->map(function ($item) {
                        return [
                            'file' => basename($item['file'] ?? ''),
                            'line' => $item['line'] ?? 0,
                            'function' => $item['function'] ?? '',
                        ];
                    })
                    ->take(5)
                    ->values()
                    ->toArray();
                
                Log::warning('Slow Query Detected', [
                    'query_type' => $queryType,
                    'sql' => $query->sql,
                    'bindings' => $query->bindings,
                    'time_ms' => round($query->time, 2),
                    'threshold_ms' => $threshold,
                    'connection' => $query->connectionName,
                    'request' => [
                        'url' => request()->fullUrl(),
                        'method' => request()->method(),
                        'ip' => request()->ip(),
                    ],
                    'user' => $userContext,
                    'stack_trace' => $trace,
                    'timestamp' => now()->toIso8601String(),
                ]);
                
                // CRITICAL: If query is extremely slow (>2000ms), log as error
                if ($query->time > 2000) {
                    Log::error('CRITICAL: Extremely Slow Query', [
                        'query_type' => $queryType,
                        'sql' => $query->sql,
                        'time_ms' => round($query->time, 2),
                        'action_required' => 'Immediate optimization needed',
                    ]);
                }
            }
        });

        Response::macro('success', function ($data = [], ?string $message = null, int $status = 200, array $headers = []) {
            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => $message,
            ], $status, $headers);
        });

        // Login rate limiting - prevent brute force attacks
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower($request->input('username')) . '|' . $request->ip();
            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'message' => 'Terlalu banyak percobaan login. Silakan coba lagi dalam beberapa menit.',
                ], 429);
            });
        });

        // Global API rate limiting
        RateLimiter::for('global', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('scan', function (Request $request) {
            $key = (($request->user()?->id) ?? 'guest') . '|' . $request->ip();

            return Limit::perMinute(30)->by($key);
        });

        RateLimiter::for('heavy_jobs', function ($job) {
            // Limit to 10 heavy jobs (exports) per school per hour
            // $job is the Job instance (e.g. GenerateReportExport)
            $schoolId = $job->reportExport->school_id ?? 'global';

            return Limit::perHour(10)->by('school:' . $schoolId);
        });

        // Sentry Error Tracking Initialization
        if (env('SENTRY_ENABLED') === true && class_exists(\Sentry\SentrySdk::class)) {
            \Sentry\init([
                'dsn' => env('SENTRY_DSN'),
                'environment' => config('app.env'),
                'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
            ]);
        }

        // Validate critical environment variables (strict in production)
        $this->validateCriticalEnvVars();
    }

    /**
     * CRITICAL: Validate environment variables at boot time
     * 
     * In production: Fails fast with exception if critical vars missing
     * In other envs: Logs warnings for missing/invalid vars
     */
    private function validateCriticalEnvVars(): void
    {
        $isProduction = $this->app->environment('production');
        
        // CRITICAL: These variables MUST exist in production
        $criticalVars = [
            'APP_KEY' => [
                'required' => true,
                'validate' => fn($v) => !empty($v) && strlen($v) >= 32,
                'message' => 'APP_KEY must be at least 32 characters',
            ],
            'QR_SECRET_KEY' => [
                'required' => true,
                'validate' => fn($v) => !empty($v) && strlen($v) >= 32,
                'message' => 'QR_SECRET_KEY must be at least 32 characters (HMAC security)',
            ],
            'DB_CONNECTION' => [
                'required' => true,
                'validate' => fn($v) => in_array($v, ['mysql', 'pgsql', 'sqlite']),
                'message' => 'DB_CONNECTION must be a valid driver',
            ],
        ];

        // Production-only strict requirements
        $productionVars = [
            'APP_DEBUG' => [
                'validate' => fn($v) => $v === 'false' || $v === false || $v === '0',
                'message' => 'APP_DEBUG must be false in production',
            ],
        ];

        $errors = [];
        $warnings = [];

        // Validate critical vars
        foreach ($criticalVars as $var => $config) {
            $value = env($var);
            
            if ($config['required'] && empty($value)) {
                $errors[] = "Missing critical ENV: {$var} - {$config['message']}";
                continue;
            }

            if (!empty($value) && isset($config['validate']) && !$config['validate']($value)) {
                $errors[] = "Invalid ENV: {$var} - {$config['message']}";
            }
        }

        // Validate production-only vars
        if ($isProduction) {
            foreach ($productionVars as $var => $config) {
                $value = env($var);
                
                if (!$config['validate']($value)) {
                    $errors[] = "Production requirement failed: {$var} - {$config['message']}";
                }
            }
        }

        // In production, fail fast if critical errors
        if ($isProduction && !empty($errors)) {
            $errorList = implode("\n- ", $errors);
            throw new \RuntimeException(
                "CRITICAL: Application cannot start due to environment errors:\n- {$errorList}\n\n" .
                "Fix these issues before deploying to production."
            );
        }

        // In non-production, log warnings
        if (!empty($errors)) {
            foreach ($errors as $error) {
                Log::warning("Environment validation: {$error}", [
                    'environment' => config('app.env'),
                    'action_required' => 'Fix before deploying to production',
                ]);
            }
        }
    }

    /**
     * CRITICAL: Enforce production security - prevent catastrophic misconfigurations
     *
     * This check prevents:
     * - Exposing sensitive data via debug traces
     * - Stack traces leaking database credentials
     * - Exception details revealing application secrets
     */
    private function enforceProductionSecurity(): void
    {
        if ($this->app->environment('production') && config('app.debug') === true) {
            // ABORT APPLICATION BOOT - This is a critical security violation
            throw new \RuntimeException(
                "CRITICAL SECURITY ERROR: APP_DEBUG=true in production environment!\n\n" .
                    "This exposes sensitive data including:\n" .
                    "- Database credentials in stack traces\n" .
                    "- API keys in exception dumps\n" .
                    "- Full file paths and source code\n\n" .
                    "IMMEDIATE ACTION REQUIRED:\n" .
                    "1. Set APP_DEBUG=false in production .env\n" .
                    "2. Run: php artisan config:cache\n" .
                    "3. Verify with: php artisan tinker -> config('app.debug')\n\n" .
                    'Application boot ABORTED for security.'
            );
        }
    }

    /**
     * Check for default/placeholder secrets that should never be in production
     *
     * Logs warnings for:
     * - Default APP_KEY (not generated)
     * - Placeholder values from .env.example
     * - Common weak secrets
     */
    private function checkDefaultSecrets(): void
    {
        $appKey = config('app.key');

        // Check if APP_KEY is empty or default placeholder
        if (empty($appKey) || $appKey === 'base64:' || strlen($appKey) < 20) {
            Log::channel('security')->critical('Default or empty APP_KEY detected!', [
                'environment' => config('app.env'),
                'key_length' => strlen($appKey ?? ''),
                'action_required' => 'Run: php artisan key:generate',
                'security_impact' => 'All encrypted data (sessions, cookies, passwords) is vulnerable',
            ]);
        }

        // Check for default QR_SECRET_KEY
        $qrSecret = config('qr.secret');
        if (empty($qrSecret) || $qrSecret === 'change-this-in-production-must-be-32-chars-minimum' || strlen($qrSecret) < 32) {
            Log::channel('security')->critical('Default or weak QR_SECRET_KEY detected!', [
                'environment' => config('app.env'),
                'key_length' => strlen($qrSecret ?? ''),
                'action_required' => 'Generate secure QR_SECRET_KEY',
                'security_impact' => 'QR codes can be forged, attendance system compromised',
            ]);
        }

        // Check for default database password
        $dbPassword = config('database.connections.pgsql.password');
        if (empty($dbPassword) || in_array($dbPassword, ['password', 'secret', 'admin', '123456'])) {
            Log::channel('security')->critical('Weak or default database password detected!', [
                'environment' => config('app.env'),
                'action_required' => 'Use strong database password',
                'security_impact' => 'Database vulnerable to unauthorized access',
            ]);
        }
    }
}
