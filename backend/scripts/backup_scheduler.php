<?php

/**
 * Backup Scheduler
 * AbsensiQR Pro - Automated Backup Scheduling System
 * 
 * This script manages the scheduling and execution of backup operations
 * based on the configured backup strategy.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BackupScheduler
{
    private $config;
    private $logFile;
    
    public function __construct()
    {
        $this->config = config('backup_schedule');
        $this->logFile = storage_path('logs/backup_scheduler.log');
    }
    
    /**
     * Main scheduler entry point
     */
    public function run(): void
    {
        $this->log("=== BACKUP SCHEDULER STARTED ===");
        
        try {
            $now = Carbon::now();
            
            // Check if it's time for full backup
            if ($this->shouldRunFullBackup($now)) {
                $this->scheduleFullBackup();
            }
            
            // Check if it's time for incremental backup
            if ($this->shouldRunIncrementalBackup($now)) {
                $this->scheduleIncrementalBackup();
            }
            
            // Check WAL/binlog archiving
            if ($this->shouldArchiveTransactionLogs($now)) {
                $this->scheduleTransactionLogArchive();
            }
            
            // Check for cleanup tasks
            if ($this->shouldRunCleanup($now)) {
                $this->scheduleCleanup();
            }
            
            $this->log("✅ Backup scheduler completed");
            
        } catch (Exception $e) {
            $this->log("❌ Backup scheduler failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Schedule full backup execution
     */
    private function scheduleFullBackup(): void
    {
        $this->log("📅 Scheduling full backup");
        
        $command = 'php ' . __DIR__ . '/advanced_backup_strategy.php backup';
        $this->executeBackgroundCommand($command, 'full_backup');
    }
    
    /**
     * Schedule incremental backup execution
     */
    private function scheduleIncrementalBackup(): void
    {
        $this->log("📅 Scheduling incremental backup");
        
        $command = 'php ' . __DIR__ . '/advanced_backup_strategy.php backup';
        $this->executeBackgroundCommand($command, 'incremental_backup');
    }
    
    /**
     * Schedule transaction log archiving
     */
    private function scheduleTransactionLogArchive(): void
    {
        $this->log("📅 Scheduling transaction log archive");
        
        $command = 'php ' . __DIR__ . '/wal_binlog_archiver.php';
        $this->executeBackgroundCommand($command, 'wal_archive');
    }
    
    /**
     * Schedule cleanup tasks
     */
    private function scheduleCleanup(): void
    {
        $this->log("📅 Scheduling cleanup tasks");
        
        $command = 'php ' . __DIR__ . '/backup_cleanup.php';
        $this->executeBackgroundCommand($command, 'cleanup');
    }
    
    /**
     * Check if full backup should run
     */
    private function shouldRunFullBackup(Carbon $now): bool
    {
        $config = $this->config['strategy']['full_backup'];
        
        // Check if it's the right day of week
        $targetDay = strtolower($config['day']);
        $currentDay = strtolower($now->format('l'));
        
        if ($currentDay !== $targetDay) {
            return false;
        }
        
        // Check if it's the right time
        $targetTime = Carbon::createFromFormat('H:i', $config['time']);
        $timeDiff = abs($now->diffInMinutes($targetTime));
        
        // Allow 5-minute window
        if ($timeDiff > 5) {
            return false;
        }
        
        // Check if backup already ran today
        return !$this->hasBackupRunToday('full');
    }
    
    /**
     * Check if incremental backup should run
     */
    private function shouldRunIncrementalBackup(Carbon $now): bool
    {
        $config = $this->config['strategy']['incremental_backup'];
        
        // Skip on full backup day if configured
        if ($config['exclude_full_backup_day'] && $this->isFullBackupDay($now)) {
            return false;
        }
        
        // Check if it's the right time
        $targetTime = Carbon::createFromFormat('H:i', $config['time']);
        $timeDiff = abs($now->diffInMinutes($targetTime));
        
        // Allow 5-minute window
        if ($timeDiff > 5) {
            return false;
        }
        
        // Check if backup already ran today
        return !$this->hasBackupRunToday('incremental');
    }
    
    /**
     * Check if transaction logs should be archived
     */
    private function shouldArchiveTransactionLogs(Carbon $now): bool
    {
        $config = $this->config['strategy']['transaction_log_archive'];
        
        if (!$config['enabled']) {
            return false;
        }
        
        $lastArchive = $this->getLastArchiveTime();
        $intervalSeconds = $config['interval_seconds'];
        
        return $now->diffInSeconds($lastArchive) >= $intervalSeconds;
    }
    
    /**
     * Check if cleanup should run
     */
    private function shouldRunCleanup(Carbon $now): bool
    {
        // Run cleanup once per day at 01:00
        $targetTime = Carbon::createFromFormat('H:i', '01:00');
        $timeDiff = abs($now->diffInMinutes($targetTime));
        
        return $timeDiff <= 5 && !$this->hasCleanupRunToday();
    }
    
    /**
     * Execute command in background
     */
    private function executeBackgroundCommand(string $command, string $type): void
    {
        $logFile = storage_path("logs/backup_{$type}_" . date('Y-m-d') . ".log");
        
        // For Windows
        if (PHP_OS_FAMILY === 'Windows') {
            $fullCommand = "start /B {$command} > {$logFile} 2>&1";
        } else {
            // For Unix/Linux
            $fullCommand = "{$command} > {$logFile} 2>&1 &";
        }
        
        exec($fullCommand);
        
        $this->log("🚀 Started background process: {$type}");
        $this->recordBackupExecution($type);
    }
    
    /**
     * Check if backup has run today
     */
    private function hasBackupRunToday(string $type): bool
    {
        $today = Carbon::today();
        
        return DB::table('backup_executions')
            ->where('backup_type', $type)
            ->whereDate('executed_at', $today)
            ->exists();
    }
    
    /**
     * Check if cleanup has run today
     */
    private function hasCleanupRunToday(): bool
    {
        $today = Carbon::today();
        
        return DB::table('backup_executions')
            ->where('backup_type', 'cleanup')
            ->whereDate('executed_at', $today)
            ->exists();
    }
    
    /**
     * Check if today is full backup day
     */
    private function isFullBackupDay(Carbon $date): bool
    {
        $targetDay = strtolower($this->config['strategy']['full_backup']['day']);
        $currentDay = strtolower($date->format('l'));
        
        return $currentDay === $targetDay;
    }
    
    /**
     * Get last archive time
     */
    private function getLastArchiveTime(): Carbon
    {
        $lastArchive = DB::table('backup_executions')
            ->where('backup_type', 'wal_archive')
            ->orderBy('executed_at', 'desc')
            ->first();
        
        return $lastArchive ? Carbon::parse($lastArchive->executed_at) : Carbon::now()->subHours(1);
    }
    
    /**
     * Record backup execution
     */
    private function recordBackupExecution(string $type): void
    {
        DB::table('backup_executions')->insert([
            'backup_type' => $type,
            'executed_at' => Carbon::now(),
            'status' => 'started',
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now()
        ]);
    }
    
    /**
     * Log message
     */
    private function log(string $message): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] {$message}\n";
        
        echo $logMessage;
        file_put_contents($this->logFile, $logMessage, FILE_APPEND);
    }
}

// CLI execution
if (php_sapi_name() === 'cli') {
    try {
        $app = require_once __DIR__ . '/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        
        $scheduler = new BackupScheduler();
        $scheduler->run();
        
    } catch (Exception $e) {
        echo "\n❌ Scheduler failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}