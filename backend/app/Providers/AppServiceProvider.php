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
        //
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

        Response::macro('success', function ($data = [], ?string $message = null, int $status = 200, array $headers = []) {
            return response()->json([
                'success' => true,
                'data' => $data,
                'message' => $message,
            ], $status, $headers);
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

        // Validate critical environment variables
        $this->validateCriticalEnvVars();
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
        $qrSecret = config('qr.secret_key');
        if (empty($qrSecret) || $qrSecret === 'your-secret-key-here' || strlen($qrSecret) < 32) {
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

    /**
     * Validate critical environment variables
     * 
     * Logs warnings for missing configuration that could impact functionality
     */
    private function validateCriticalEnvVars(): void
    {
        $criticalVars = [
            'RATE_LIMIT_LOGIN' => [
                'description' => 'Login rate limit configuration',
                'impact' => 'Using default value (5), may not match production requirements',
            ],
            'RATE_LIMIT_QR_SCAN' => [
                'description' => 'QR scan rate limit configuration',
                'impact' => 'Using default value (30), may not match production requirements',
            ],
            'APP_KEY' => [
                'description' => 'Application encryption key',
                'impact' => 'CRITICAL: Application cannot function without encryption key',
            ],
            'QUEUE_CONNECTION' => [
                'description' => 'Queue driver configuration',
                'impact' => 'Using default queue driver, async jobs may not work as expected',
            ],
        ];

        foreach ($criticalVars as $var => $info) {
            $value = env($var);
            
            if ($value === null || $value === '') {
                Log::warning("Missing critical environment variable: {$var}", [
                    'variable' => $var,
                    'description' => $info['description'],
                    'impact' => $info['impact'],
                    'environment' => config('app.env'),
                    'action_required' => "Set {$var} in .env file",
                ]);
            }
        }
    }
}
