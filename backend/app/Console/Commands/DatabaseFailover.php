<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

class DatabaseFailover extends Command
{
    protected $signature = 'db:failover 
                            {action : Action to perform: promote|switchback|status}
                            {--force : Skip confirmation prompts}
                            {--dry-run : Show what would happen without making changes}';

    protected $description = 'Manage database failover: promote replica to primary or switch back';

    private string $envPath;

    public function __construct()
    {
        parent::__construct();
        $this->envPath = base_path('.env');
    }

    public function handle(): int
    {
        $action = $this->argument('action');

        return match ($action) {
            'promote' => $this->promoteReplica(),
            'switchback' => $this->switchBack(),
            'status' => $this->showStatus(),
            default => $this->error("Unknown action: {$action}") ?? self::FAILURE,
        };
    }

    /**
     * Promote replica to primary (failover)
     */
    private function promoteReplica(): int
    {
        $this->warn('⚠️  DATABASE FAILOVER - Promoting Replica to Primary');
        $this->newLine();

        // Pre-checks
        $this->info('Running pre-flight checks...');
        
        $replicaHost = env('DB_REPLICA_HOST');
        $primaryHost = env('DB_HOST');

        if (!$replicaHost) {
            $this->error('No replica configured (DB_REPLICA_HOST not set)');
            return self::FAILURE;
        }

        $this->line("  Current Primary: {$primaryHost}");
        $this->line("  Current Replica: {$replicaHost}");
        $this->line("  Target Primary:  {$replicaHost} (after failover)");
        $this->newLine();

        // Check replica health
        $this->info('Checking replica connectivity...');
        try {
            $replicaOk = $this->testConnection($replicaHost, env('DB_REPLICA_PORT', 5432));
            if (!$replicaOk) {
                $this->error('Cannot connect to replica!');
                return self::FAILURE;
            }
            $this->line('  ✅ Replica is reachable');
        } catch (\Exception $e) {
            $this->error('Replica connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Confirmation
        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Are you sure you want to failover to the replica?')) {
                $this->info('Failover cancelled.');
                return self::SUCCESS;
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] Would perform the following:');
            $this->line("  1. Backup current .env");
            $this->line("  2. Set DB_HOST={$replicaHost}");
            $this->line("  3. Set DB_REPLICA_ENABLED=false");
            $this->line("  4. Clear config cache");
            $this->line("  5. Log failover event");
            return self::SUCCESS;
        }

        // Execute failover
        $this->info('Executing failover...');

        try {
            // 1. Backup current .env
            $this->backupEnv();
            $this->line('  ✅ .env backed up');

            // 2. Update .env to point to replica
            $this->updateEnvValue('DB_HOST', $replicaHost);
            $this->updateEnvValue('DB_PORT', env('DB_REPLICA_PORT', '5432'));
            $this->updateEnvValue('DB_REPLICA_ENABLED', 'false');
            $this->updateEnvValue('DB_FAILOVER_ACTIVE', 'true');
            $this->updateEnvValue('DB_ORIGINAL_PRIMARY_HOST', $primaryHost);
            $this->line('  ✅ .env updated');

            // 3. Clear config cache
            $this->call('config:clear');
            $this->line('  ✅ Config cache cleared');

            // 4. Log the failover
            Log::critical('DATABASE FAILOVER EXECUTED', [
                'old_primary' => $primaryHost,
                'new_primary' => $replicaHost,
                'timestamp' => now()->toIso8601String(),
                'initiated_by' => get_current_user(),
            ]);

            // 5. Cache failover state
            Cache::forever('db:failover:active', [
                'old_primary' => $primaryHost,
                'new_primary' => $replicaHost,
                'timestamp' => now()->toIso8601String(),
            ]);

            $this->newLine();
            $this->info('✅ FAILOVER COMPLETE');
            $this->warn('⚠️  Remember to:');
            $this->line('  1. Promote replica to standalone in PostgreSQL');
            $this->line('  2. Monitor application for issues');
            $this->line('  3. Investigate and fix the original primary');
            $this->line('  4. Run: php artisan db:failover switchback (when ready)');

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Failover failed: ' . $e->getMessage());
            Log::emergency('DATABASE FAILOVER FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Switch back to original primary
     */
    private function switchBack(): int
    {
        $this->info('🔄 DATABASE SWITCHBACK - Restoring Original Primary');
        $this->newLine();

        $originalPrimary = env('DB_ORIGINAL_PRIMARY_HOST');
        $currentPrimary = env('DB_HOST');

        if (!$originalPrimary) {
            $this->error('No original primary recorded (DB_ORIGINAL_PRIMARY_HOST not set)');
            $this->line('This suggests failover was not performed via this tool.');
            return self::FAILURE;
        }

        if (!env('DB_FAILOVER_ACTIVE', false)) {
            $this->warn('Failover is not currently active.');
            if (!$this->option('force')) {
                return self::SUCCESS;
            }
        }

        $this->line("  Current Primary (failover):  {$currentPrimary}");
        $this->line("  Original Primary (restore):  {$originalPrimary}");
        $this->newLine();

        // Test original primary
        $this->info('Testing original primary connectivity...');
        try {
            if (!$this->testConnection($originalPrimary, env('DB_PORT', 5432))) {
                $this->error('Cannot connect to original primary!');
                $this->line('Ensure the original primary is repaired and running.');
                return self::FAILURE;
            }
            $this->line('  ✅ Original primary is reachable');
        } catch (\Exception $e) {
            $this->error('Connection failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        // Confirmation
        if (!$this->option('force') && !$this->option('dry-run')) {
            if (!$this->confirm('Switch back to original primary?')) {
                $this->info('Switchback cancelled.');
                return self::SUCCESS;
            }
        }

        if ($this->option('dry-run')) {
            $this->warn('[DRY RUN] Would restore:');
            $this->line("  DB_HOST={$originalPrimary}");
            $this->line("  DB_FAILOVER_ACTIVE=false");
            $this->line("  DB_REPLICA_ENABLED=true");
            return self::SUCCESS;
        }

        try {
            // Restore configuration
            $this->updateEnvValue('DB_HOST', $originalPrimary);
            $this->updateEnvValue('DB_FAILOVER_ACTIVE', 'false');
            $this->updateEnvValue('DB_REPLICA_ENABLED', 'true');
            $this->updateEnvValue('DB_REPLICA_HOST', $currentPrimary); // Old failover becomes replica

            $this->call('config:clear');

            Log::info('DATABASE SWITCHBACK COMPLETED', [
                'restored_primary' => $originalPrimary,
                'new_replica' => $currentPrimary,
            ]);

            Cache::forget('db:failover:active');

            $this->newLine();
            $this->info('✅ SWITCHBACK COMPLETE');
            $this->line("  Primary: {$originalPrimary}");
            $this->line("  Replica: {$currentPrimary}");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Switchback failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Show current failover status
     */
    private function showStatus(): int
    {
        $this->info('=== Database Replication Status ===');
        $this->newLine();

        $failoverActive = env('DB_FAILOVER_ACTIVE', false);
        $replicaEnabled = env('DB_REPLICA_ENABLED', false);

        $this->table(['Setting', 'Value'], [
            ['DB_HOST (Primary)', env('DB_HOST', 'not set')],
            ['DB_PORT', env('DB_PORT', '5432')],
            ['DB_REPLICA_HOST', env('DB_REPLICA_HOST', 'not set')],
            ['DB_REPLICA_PORT', env('DB_REPLICA_PORT', '5432')],
            ['DB_REPLICA_ENABLED', $replicaEnabled ? 'true' : 'false'],
            ['DB_FAILOVER_ACTIVE', $failoverActive ? 'true' : 'false'],
            ['DB_ORIGINAL_PRIMARY_HOST', env('DB_ORIGINAL_PRIMARY_HOST', 'not set')],
            ['DB_STICKY', env('DB_STICKY', true) ? 'true' : 'false'],
        ]);

        $this->newLine();

        if ($failoverActive) {
            $this->warn('⚠️  FAILOVER MODE ACTIVE');
            $this->line('   Original primary: ' . env('DB_ORIGINAL_PRIMARY_HOST'));
            
            $cachedState = Cache::get('db:failover:active');
            if ($cachedState) {
                $this->line('   Failover time: ' . $cachedState['timestamp']);
            }
        } else {
            $this->info('✅ Normal operation mode');
        }

        if ($replicaEnabled) {
            $this->info('📖 Read/Write split: ENABLED');
            $this->line('   Reads → Replica');
            $this->line('   Writes → Primary');
        } else {
            $this->line('📖 Read/Write split: DISABLED (all queries to primary)');
        }

        return self::SUCCESS;
    }

    /**
     * Test database connection
     */
    private function testConnection(string $host, int $port): bool
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $host,
            $port,
            env('DB_DATABASE')
        );

        try {
            $pdo = new \PDO(
                $dsn,
                env('DB_USERNAME'),
                env('DB_PASSWORD'),
                [\PDO::ATTR_TIMEOUT => 5]
            );
            $pdo->query('SELECT 1');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Backup .env file
     */
    private function backupEnv(): void
    {
        $backupPath = base_path('.env.backup.' . date('Y-m-d-His'));
        File::copy($this->envPath, $backupPath);
    }

    /**
     * Update a value in .env file
     */
    private function updateEnvValue(string $key, string $value): void
    {
        $content = File::get($this->envPath);
        
        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";
        
        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= "\n{$replacement}";
        }
        
        File::put($this->envPath, $content);
    }
}
