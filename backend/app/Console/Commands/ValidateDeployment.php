<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-Deploy Validation Command
 * 
 * Validates environment, database, and configuration before deployment.
 * Used in CI/CD pipeline to prevent broken deployments.
 */
class ValidateDeployment extends Command
{
    protected $signature = 'deploy:validate 
                            {--strict : Fail on any warning}
                            {--skip-db : Skip database validation}
                            {--skip-redis : Skip Redis validation}';

    protected $description = 'Validate deployment readiness: ENV, database, Redis, migrations';

    private array $errors = [];
    private array $warnings = [];

    public function handle(): int
    {
        $this->info('🔍 Starting pre-deployment validation...');
        $this->newLine();

        // 1. Validate critical environment variables
        $this->validateEnvironment();

        // 2. Validate database connection and migrations
        if (!$this->option('skip-db')) {
            $this->validateDatabase();
        }

        // 3. Validate Redis connection
        if (!$this->option('skip-redis')) {
            $this->validateRedis();
        }

        // 4. Validate application configuration
        $this->validateConfiguration();

        // 5. Validate security settings
        $this->validateSecurity();

        // 6. Validate file permissions
        $this->validatePermissions();

        // Report results
        return $this->reportResults();
    }

    /**
     * Validate critical environment variables
     */
    private function validateEnvironment(): void
    {
        $this->info('📋 Validating environment variables...');

        $requiredVars = [
            'APP_KEY' => [
                'required' => true,
                'validate' => fn($v) => strlen($v) >= 32,
                'message' => 'APP_KEY must be at least 32 characters',
            ],
            'APP_ENV' => [
                'required' => true,
                'validate' => fn($v) => in_array($v, ['local', 'staging', 'production']),
                'message' => 'APP_ENV must be local, staging, or production',
            ],
            'DB_CONNECTION' => [
                'required' => true,
                'validate' => fn($v) => in_array($v, ['mysql', 'pgsql', 'sqlite']),
                'message' => 'Invalid database driver',
            ],
            'DB_HOST' => [
                'required' => true,
                'validate' => fn($v) => !empty($v),
                'message' => 'Database host is required',
            ],
            'QR_SECRET_KEY' => [
                'required' => true,
                'validate' => fn($v) => strlen($v) >= 32,
                'message' => 'QR_SECRET_KEY must be at least 32 characters for security',
            ],
        ];

        // Additional vars required in production
        if (config('app.env') === 'production') {
            $requiredVars = array_merge($requiredVars, [
                'APP_DEBUG' => [
                    'required' => true,
                    'validate' => fn($v) => $v === 'false' || $v === false,
                    'message' => 'APP_DEBUG must be false in production',
                ],
                'LOG_CHANNEL' => [
                    'required' => false,
                    'validate' => fn($v) => !empty($v),
                    'message' => 'LOG_CHANNEL should be configured for production',
                ],
                'MAIL_MAILER' => [
                    'required' => false,
                    'validate' => fn($v) => $v !== 'log',
                    'message' => 'MAIL_MAILER should not be "log" in production',
                ],
                'CACHE_DRIVER' => [
                    'required' => false,
                    'validate' => fn($v) => in_array($v, ['redis', 'memcached', 'database']),
                    'message' => 'CACHE_DRIVER should use Redis or Memcached in production',
                ],
                'QUEUE_CONNECTION' => [
                    'required' => false,
                    'validate' => fn($v) => $v !== 'sync',
                    'message' => 'QUEUE_CONNECTION should not be "sync" in production',
                ],
                'SESSION_DRIVER' => [
                    'required' => false,
                    'validate' => fn($v) => in_array($v, ['redis', 'database', 'memcached']),
                    'message' => 'SESSION_DRIVER should use Redis or Database in production',
                ],
            ]);
        }

        foreach ($requiredVars as $var => $config) {
            $value = env($var);
            
            if ($config['required'] && ($value === null || $value === '')) {
                $this->errors[] = "❌ Missing required ENV: {$var}";
                continue;
            }

            if ($value !== null && $value !== '' && isset($config['validate'])) {
                if (!$config['validate']($value)) {
                    if ($config['required']) {
                        $this->errors[] = "❌ {$var}: {$config['message']}";
                    } else {
                        $this->warnings[] = "⚠️ {$var}: {$config['message']}";
                    }
                }
            }
        }

        $this->line('   Environment validation complete');
    }

    /**
     * Validate database connection and migrations
     */
    private function validateDatabase(): void
    {
        $this->info('🗄️ Validating database...');

        // Test connection
        try {
            DB::connection()->getPdo();
            $this->line('   ✅ Database connection successful');
        } catch (\Exception $e) {
            $this->errors[] = "❌ Database connection failed: {$e->getMessage()}";
            return;
        }

        // Check pending migrations
        try {
            $pendingMigrations = $this->getPendingMigrations();
            
            if (!empty($pendingMigrations)) {
                $count = count($pendingMigrations);
                $this->warnings[] = "⚠️ {$count} pending migration(s) detected";
                
                foreach ($pendingMigrations as $migration) {
                    $this->line("      - {$migration}");
                }
            } else {
                $this->line('   ✅ All migrations are up to date');
            }
        } catch (\Exception $e) {
            $this->warnings[] = "⚠️ Could not check migrations: {$e->getMessage()}";
        }

        // Validate critical tables exist
        $criticalTables = [
            'users',
            'schools',
            'attendances',
            'schedules',
            'qr_codes',
            'personal_access_tokens',
        ];

        foreach ($criticalTables as $table) {
            if (!Schema::hasTable($table)) {
                $this->errors[] = "❌ Critical table missing: {$table}";
            }
        }
    }

    /**
     * Get list of pending migrations
     */
    private function getPendingMigrations(): array
    {
        $migrator = app('migrator');
        $migrator->setConnection(DB::connection()->getName());
        
        $ran = $migrator->getRepository()->getRan();
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        
        return array_diff(array_keys($files), $ran);
    }

    /**
     * Validate Redis connection
     */
    private function validateRedis(): void
    {
        $this->info('📡 Validating Redis...');

        if (config('cache.default') !== 'redis' && config('queue.default') !== 'redis') {
            $this->line('   ⏭️ Redis not configured as primary cache/queue, skipping');
            return;
        }

        try {
            Redis::ping();
            $this->line('   ✅ Redis connection successful');
        } catch (\Exception $e) {
            $this->warnings[] = "⚠️ Redis connection failed: {$e->getMessage()}";
        }
    }

    /**
     * Validate application configuration
     */
    private function validateConfiguration(): void
    {
        $this->info('⚙️ Validating configuration...');

        // Check if config can be cached
        try {
            $this->callSilently('config:cache');
            $this->callSilently('config:clear');
            $this->line('   ✅ Configuration is cacheable');
        } catch (\Exception $e) {
            $this->errors[] = "❌ Configuration caching failed: {$e->getMessage()}";
        }

        // Check routes
        try {
            $this->callSilently('route:cache');
            $this->callSilently('route:clear');
            $this->line('   ✅ Routes are cacheable');
        } catch (\Exception $e) {
            $this->warnings[] = "⚠️ Route caching failed: {$e->getMessage()}";
        }

        // Check scheduled tasks
        $scheduledCount = count(app(\Illuminate\Console\Scheduling\Schedule::class)->events());
        $this->line("   📅 {$scheduledCount} scheduled task(s) registered");
    }

    /**
     * Validate security settings
     */
    private function validateSecurity(): void
    {
        $this->info('🔐 Validating security...');

        // Check HTTPS enforcement in production
        if (config('app.env') === 'production') {
            if (!config('app.url') || !str_starts_with(config('app.url'), 'https://')) {
                $this->warnings[] = '⚠️ APP_URL should use HTTPS in production';
            }

            // Check session security
            if (!config('session.secure')) {
                $this->warnings[] = '⚠️ SESSION_SECURE_COOKIE should be true in production';
            }

            // Check CSRF
            if (config('session.same_site') !== 'strict' && config('session.same_site') !== 'lax') {
                $this->warnings[] = '⚠️ SESSION_SAME_SITE should be "strict" or "lax"';
            }
        }

        // Check Sanctum configuration
        if (!config('sanctum.stateful')) {
            $this->warnings[] = '⚠️ Sanctum stateful domains not configured';
        }

        $this->line('   Security validation complete');
    }

    /**
     * Validate file permissions
     */
    private function validatePermissions(): void
    {
        $this->info('📁 Validating permissions...');

        $writablePaths = [
            storage_path(),
            storage_path('logs'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            base_path('bootstrap/cache'),
        ];

        foreach ($writablePaths as $path) {
            if (!is_writable($path)) {
                $this->errors[] = "❌ Path not writable: {$path}";
            }
        }

        $this->line('   Permissions validation complete');
    }

    /**
     * Report validation results
     */
    private function reportResults(): int
    {
        $this->newLine();
        $this->info('📊 Validation Results');
        $this->line('═══════════════════════════════════════');

        // Show warnings
        if (!empty($this->warnings)) {
            $this->newLine();
            $this->warn('Warnings (' . count($this->warnings) . '):');
            foreach ($this->warnings as $warning) {
                $this->line("   {$warning}");
            }
        }

        // Show errors
        if (!empty($this->errors)) {
            $this->newLine();
            $this->error('Errors (' . count($this->errors) . '):');
            foreach ($this->errors as $error) {
                $this->line("   {$error}");
            }
        }

        $this->newLine();

        // Determine exit code
        if (!empty($this->errors)) {
            $this->error('❌ Validation FAILED - Fix errors before deploying');
            return Command::FAILURE;
        }

        if ($this->option('strict') && !empty($this->warnings)) {
            $this->error('❌ Validation FAILED (strict mode) - Fix warnings before deploying');
            return Command::FAILURE;
        }

        $this->info('✅ Validation PASSED - Ready for deployment');
        return Command::SUCCESS;
    }
}
