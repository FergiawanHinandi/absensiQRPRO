<?php

namespace App\Console\Commands;

use App\Models\BackupJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class BackupMonitoringCleanupCommand extends Command
{
    protected $signature = 'backup:monitoring-cleanup 
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--force : Force cleanup without confirmation}';

    protected $description = 'Clean up old backup monitoring data and logs';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $this->info('🧹 Starting backup monitoring cleanup...');
        
        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No data will be deleted');
        }

        // Get retention periods from config
        $jobRetentionDays = config('backup_monitoring.monitoring.job_retention_days', 90);
        $logRetentionDays = config('backup_monitoring.monitoring.log_retention_days', 30);

        $this->line("📅 Job retention period: {$jobRetentionDays} days");
        $this->line("📅 Log retention period: {$logRetentionDays} days");

        // Cleanup old backup jobs
        $this->cleanupBackupJobs($jobRetentionDays, $dryRun, $force);

        // Cleanup old log files
        $this->cleanupLogFiles($logRetentionDays, $dryRun, $force);

        // Cleanup temporary files
        $this->cleanupTempFiles($dryRun, $force);

        $this->info('✅ Cleanup completed successfully');
    }

    private function cleanupBackupJobs(int $retentionDays, bool $dryRun, bool $force): void
    {
        $this->newLine();
        $this->info('🗄️  Cleaning up old backup jobs...');

        $cutoffDate = now()->subDays($retentionDays);
        $oldJobs = BackupJob::where('created_at', '<', $cutoffDate)->get();

        $totalJobs = $oldJobs->count();
        
        if ($totalJobs === 0) {
            $this->line('✨ No old jobs to clean up');
            return;
        }

        $this->line("📊 Found {$totalJobs} jobs older than {$retentionDays} days");

        // Show breakdown by status
        $statusBreakdown = $oldJobs->groupBy('status')->map->count();
        foreach ($statusBreakdown as $status => $count) {
            $this->line("   • {$status}: {$count} jobs");
        }

        if (!$dryRun) {
            if (!$force && !$this->confirm("Delete {$totalJobs} old backup jobs?")) {
                $this->info('❌ Cancelled job cleanup');
                return;
            }

            $deletedCount = BackupJob::where('created_at', '<', $cutoffDate)->delete();
            $this->info("🗑️  Deleted {$deletedCount} old backup jobs");

            Log::channel('backup')->info('Backup monitoring cleanup', [
                'action' => 'cleanup_jobs',
                'deleted_count' => $deletedCount,
                'retention_days' => $retentionDays
            ]);
        } else {
            $this->warn("🔍 DRY RUN: Would delete {$totalJobs} old backup jobs");
        }
    }

    private function cleanupLogFiles(int $retentionDays, bool $dryRun, bool $force): void
    {
        $this->newLine();
        $this->info('📋 Cleaning up old log files...');

        $logPaths = [
            storage_path('logs/backup.log'),
            storage_path('logs/rollback.log'),
            storage_path('logs/backup-' . date('Y-m-d') . '.log'),
            storage_path('logs/rollback-' . date('Y-m-d') . '.log'),
        ];

        $totalSize = 0;
        $fileCount = 0;

        foreach ($logPaths as $logPath) {
            if (file_exists($logPath)) {
                $fileSize = filesize($logPath);
                $totalSize += $fileSize;
                $fileCount++;

                $this->line("📄 Found log file: " . basename($logPath) . " (" . $this->formatBytes($fileSize) . ")");
            }
        }

        // Check for rotated log files
        $logDir = storage_path('logs');
        if (is_dir($logDir)) {
            $rotatedLogs = glob($logDir . '/backup-*.log') + glob($logDir . '/rollback-*.log');
            
            foreach ($rotatedLogs as $logFile) {
                $fileTime = filemtime($logFile);
                if ($fileTime < strtotime("-{$retentionDays} days")) {
                    $fileSize = filesize($logFile);
                    $totalSize += $fileSize;
                    $fileCount++;

                    $this->line("📄 Found old rotated log: " . basename($logFile) . " (" . $this->formatBytes($fileSize) . ")");
                }
            }
        }

        if ($fileCount === 0) {
            $this->line('✨ No old log files to clean up');
            return;
        }

        $this->line("📊 Total log files to clean: {$fileCount}");
        $this->line("💾 Total size to free: " . $this->formatBytes($totalSize));

        if (!$dryRun) {
            if (!$force && !$this->confirm("Delete {$fileCount} old log files?")) {
                $this->info('❌ Cancelled log cleanup');
                return;
            }

            // Delete rotated log files older than retention period
            $deletedFiles = 0;
            $cutoffTime = strtotime("-{$retentionDays} days");
            
            foreach ($rotatedLogs as $logFile) {
                if (filemtime($logFile) < $cutoffTime) {
                    unlink($logFile);
                    $deletedFiles++;
                }
            }

            $this->info("🗑️  Deleted {$deletedFiles} old log files");

            Log::channel('backup')->info('Backup monitoring cleanup', [
                'action' => 'cleanup_logs',
                'deleted_files' => $deletedFiles,
                'retention_days' => $retentionDays,
                'size_freed' => $totalSize
            ]);
        } else {
            $this->warn("🔍 DRY RUN: Would delete {$fileCount} old log files");
        }
    }

    private function cleanupTempFiles(bool $dryRun, bool $force): void
    {
        $this->newLine();
        $this->info('🗂️  Cleaning up temporary files...');

        $tempPaths = [
            storage_path('app/temp/'),
            storage_path('app/backups/temp/'),
        ];

        $totalFiles = 0;
        $totalSize = 0;

        foreach ($tempPaths as $tempPath) {
            if (is_dir($tempPath)) {
                $files = glob($tempPath . '*');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $fileSize = filesize($file);
                        $totalSize += $fileSize;
                        $totalFiles++;

                        $this->line("📄 Found temp file: " . basename($file) . " (" . $this->formatBytes($fileSize) . ")");
                    }
                }
            }
        }

        if ($totalFiles === 0) {
            $this->line('✨ No temporary files to clean up');
            return;
        }

        $this->line("📊 Total temp files to clean: {$totalFiles}");
        $this->line("💾 Total size to free: " . $this->formatBytes($totalSize));

        if (!$dryRun) {
            if (!$force && !$this->confirm("Delete {$totalFiles} temporary files?")) {
                $this->info('❌ Cancelled temp file cleanup');
                return;
            }

            $deletedFiles = 0;
            foreach ($tempPaths as $tempPath) {
                if (is_dir($tempPath)) {
                    $files = glob($tempPath . '*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                            $deletedFiles++;
                        }
                    }
                }
            }

            $this->info("🗑️  Deleted {$deletedFiles} temporary files");

            Log::channel('backup')->info('Backup monitoring cleanup', [
                'action' => 'cleanup_temp',
                'deleted_files' => $deletedFiles,
                'size_freed' => $totalSize
            ]);
        } else {
            $this->warn("🔍 DRY RUN: Would delete {$totalFiles} temporary files");
        }
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, 2) . ' ' . $units[$pow];
    }
}
