<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Environment Validation Command
 * 
 * Validates that all required environment variables are set.
 * Used during CI/CD and application boot.
 */
class ValidateEnvironment extends Command
{
    protected $signature = 'env:validate 
                            {--production : Validate production requirements}
                            {--json : Output as JSON}';

    protected $description = 'Validate environment variables are properly configured';

    public function handle(): int
    {
        $isProduction = $this->option('production') || config('app.env') === 'production';
        $errors = [];
        $warnings = [];

        // =====================================================================
        // CRITICAL VARIABLES (Always Required)
        // =====================================================================
        $critical = [
            'APP_KEY' => [
                'validate' => fn($v) => !empty($v) && strlen($v) >= 32,
                'message' => 'Must be at least 32 characters',
            ],
            'APP_ENV' => [
                'validate' => fn($v) => in_array($v, ['local', 'testing', 'staging', 'production']),
                'message' => 'Must be local, testing, staging, or production',
            ],
            'DB_CONNECTION' => [
                'validate' => fn($v) => in_array($v, ['mysql', 'pgsql', 'sqlite', 'sqlsrv']),
                'message' => 'Must be a valid database driver',
            ],
            'QR_SECRET_KEY' => [
                'validate' => fn($v) => !empty($v) && strlen($v) >= 32,
                'message' => 'Must be at least 32 characters for HMAC security',
            ],
        ];

        foreach ($critical as $var => $config) {
            $value = env($var);
            
            if (empty($value)) {
                $errors[] = [
                    'variable' => $var,
                    'type' => 'missing',
                    'message' => "Missing required variable: {$var}",
                ];
                continue;
            }

            if (!$config['validate']($value)) {
                $errors[] = [
                    'variable' => $var,
                    'type' => 'invalid',
                    'message' => "{$var}: {$config['message']}",
                ];
            }
        }

        // =====================================================================
        // PRODUCTION-ONLY REQUIREMENTS
        // =====================================================================
        if ($isProduction) {
            $productionRequired = [
                'APP_DEBUG' => [
                    'validate' => fn($v) => $v === 'false' || $v === false || $v === '0' || $v === 0,
                    'message' => 'APP_DEBUG must be false in production',
                    'severity' => 'error',
                ],
                'APP_URL' => [
                    'validate' => fn($v) => !empty($v) && str_starts_with($v, 'https://'),
                    'message' => 'APP_URL should use HTTPS in production',
                    'severity' => 'warning',
                ],
                'LOG_LEVEL' => [
                    'validate' => fn($v) => in_array($v, ['warning', 'error', 'critical', 'alert', 'emergency']),
                    'message' => 'LOG_LEVEL should be warning or higher in production',
                    'severity' => 'warning',
                ],
                'CACHE_DRIVER' => [
                    'validate' => fn($v) => in_array($v, ['redis', 'memcached', 'database', 'dynamodb']),
                    'message' => 'CACHE_DRIVER should use Redis/Memcached in production',
                    'severity' => 'warning',
                ],
                'QUEUE_CONNECTION' => [
                    'validate' => fn($v) => $v !== 'sync',
                    'message' => 'QUEUE_CONNECTION should not be sync in production',
                    'severity' => 'warning',
                ],
                'SESSION_DRIVER' => [
                    'validate' => fn($v) => in_array($v, ['redis', 'database', 'memcached']),
                    'message' => 'SESSION_DRIVER should use Redis/Database in production',
                    'severity' => 'warning',
                ],
                'MAIL_MAILER' => [
                    'validate' => fn($v) => $v !== 'log' && $v !== 'array',
                    'message' => 'MAIL_MAILER should not be log/array in production',
                    'severity' => 'warning',
                ],
            ];

            foreach ($productionRequired as $var => $config) {
                $value = env($var);
                
                if (empty($value) || !$config['validate']($value)) {
                    $item = [
                        'variable' => $var,
                        'type' => 'production',
                        'message' => $config['message'],
                    ];

                    if ($config['severity'] === 'error') {
                        $errors[] = $item;
                    } else {
                        $warnings[] = $item;
                    }
                }
            }
        }

        // =====================================================================
        // OUTPUT RESULTS
        // =====================================================================
        if ($this->option('json')) {
            $this->line(json_encode([
                'valid' => empty($errors),
                'environment' => config('app.env'),
                'errors' => $errors,
                'warnings' => $warnings,
            ], JSON_PRETTY_PRINT));
        } else {
            $this->info('🔍 Environment Validation');
            $this->line('Environment: ' . config('app.env'));
            $this->newLine();

            if (!empty($errors)) {
                $this->error('Errors (' . count($errors) . '):');
                foreach ($errors as $error) {
                    $this->line("   ❌ {$error['message']}");
                }
                $this->newLine();
            }

            if (!empty($warnings)) {
                $this->warn('Warnings (' . count($warnings) . '):');
                foreach ($warnings as $warning) {
                    $this->line("   ⚠️ {$warning['message']}");
                }
                $this->newLine();
            }

            if (empty($errors) && empty($warnings)) {
                $this->info('✅ All environment variables are valid');
            } elseif (empty($errors)) {
                $this->info('✅ Required variables are valid (warnings present)');
            } else {
                $this->error('❌ Environment validation failed');
            }
        }

        return empty($errors) ? Command::SUCCESS : Command::FAILURE;
    }
}
