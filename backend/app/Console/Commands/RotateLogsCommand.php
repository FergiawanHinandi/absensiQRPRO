<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Command to rotate and clean up old log files
 * 
 * Schedule this command daily: $schedule->command('logs:rotate')->daily();
 */
class RotateLogsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'logs:rotate 
                            {--days=30 : Number of days to keep logs}
                            {--max-size=100 : Max size in MB before rotating}
                            {--dry-run : Show what would be deleted without actually deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Rotate and clean up old log files';

    /**
     * Log patterns to rotate
     */
    private array $logPatterns = [
        '*.log',
        'laravel-*.log',
        'worker-*.log',
        'scheduler.log',
        'reverb.log',
        'horizon.log',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $logsPath = storage_path('logs');
        $days = (int) $this->option('days');
        $maxSize = (int) $this->option('max-size') * 1024 * 1024; // Convert to bytes
        $dryRun = $this->option('dry-run');

        $this->info('Log Rotation Started');
        $this->info(sprintf('  Retention: %d days', $days));
        $this->info(sprintf('  Max size: %d MB', $this->option('max-size')));
        $this->line('');

        if (!File::isDirectory($logsPath)) {
            $this->error('Logs directory not found: ' . $logsPath);
            return 1;
        }

        $deletedCount = 0;
        $deletedSize = 0;
        $rotatedCount = 0;
        $cutoffDate = now()->subDays($days);

        // Get all log files
        $logFiles = File::glob($logsPath . '/*.log');

        foreach ($logFiles as $file) {
            $fileName = basename($file);
            $fileSize = File::size($file);
            $fileModified = File::lastModified($file);
            $fileDate = date('Y-m-d H:i:s', $fileModified);

            // Check if file is older than retention period
            if ($fileModified < $cutoffDate->timestamp) {
                if ($dryRun) {
                    $this->line(sprintf(
                        '  [DRY-RUN] Would delete: %s (modified: %s, size: %s)',
                        $fileName,
                        $fileDate,
                        $this->formatBytes($fileSize)
                    ));
                } else {
                    File::delete($file);
                    $this->line(sprintf(
                        '  Deleted: %s (modified: %s, size: %s)',
                        $fileName,
                        $fileDate,
                        $this->formatBytes($fileSize)
                    ));
                }
                $deletedCount++;
                $deletedSize += $fileSize;
                continue;
            }

            // Check if file exceeds max size - rotate it
            if ($fileSize > $maxSize) {
                $rotatedFile = $file . '.' . date('Y-m-d-His');
                
                if ($dryRun) {
                    $this->line(sprintf(
                        '  [DRY-RUN] Would rotate: %s → %s (size: %s)',
                        $fileName,
                        basename($rotatedFile),
                        $this->formatBytes($fileSize)
                    ));
                } else {
                    // Rename current log
                    File::move($file, $rotatedFile);
                    
                    // Create new empty log file
                    File::put($file, '');
                    
                    // Compress rotated file if gzip available
                    if ($this->canGzip()) {
                        $this->compressFile($rotatedFile);
                    }
                    
                    $this->line(sprintf(
                        '  Rotated: %s → %s (size: %s)',
                        $fileName,
                        basename($rotatedFile),
                        $this->formatBytes($fileSize)
                    ));
                }
                $rotatedCount++;
            }
        }

        // Clean up old compressed logs
        $compressedFiles = File::glob($logsPath . '/*.log.*.gz');
        foreach ($compressedFiles as $file) {
            $fileModified = File::lastModified($file);
            if ($fileModified < $cutoffDate->timestamp) {
                $fileSize = File::size($file);
                if (!$dryRun) {
                    File::delete($file);
                }
                $deletedCount++;
                $deletedSize += $fileSize;
                $this->line(sprintf(
                    '  %sDeleted compressed: %s',
                    $dryRun ? '[DRY-RUN] Would delete: ' : '',
                    basename($file)
                ));
            }
        }

        // Clean up rotated but uncompressed logs
        $rotatedFiles = File::glob($logsPath . '/*.log.[0-9]*');
        foreach ($rotatedFiles as $file) {
            $fileModified = File::lastModified($file);
            if ($fileModified < $cutoffDate->timestamp) {
                $fileSize = File::size($file);
                if (!$dryRun) {
                    File::delete($file);
                }
                $deletedCount++;
                $deletedSize += $fileSize;
                $this->line(sprintf(
                    '  %sDeleted rotated: %s',
                    $dryRun ? '[DRY-RUN] Would delete: ' : '',
                    basename($file)
                ));
            }
        }

        $this->line('');
        $this->info('Log Rotation Complete');
        $this->info(sprintf('  Files deleted: %d', $deletedCount));
        $this->info(sprintf('  Space freed: %s', $this->formatBytes($deletedSize)));
        $this->info(sprintf('  Files rotated: %d', $rotatedCount));

        if (!$dryRun) {
            Log::channel('system')->info('Log rotation completed', [
                'deleted_count' => $deletedCount,
                'deleted_size_bytes' => $deletedSize,
                'rotated_count' => $rotatedCount,
                'retention_days' => $days,
            ]);
        }

        return 0;
    }

    /**
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $factor = floor((strlen((string) $bytes) - 1) / 3);
        return sprintf('%.2f %s', $bytes / pow(1024, $factor), $units[$factor] ?? 'TB');
    }

    /**
     * Check if gzip is available
     */
    private function canGzip(): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return false; // Skip compression on Windows by default
        }
        return shell_exec('which gzip 2>/dev/null') !== null;
    }

    /**
     * Compress a file using gzip
     */
    private function compressFile(string $filePath): void
    {
        $output = shell_exec(sprintf('gzip -9 %s 2>&1', escapeshellarg($filePath)));
        if (!file_exists($filePath . '.gz')) {
            Log::channel('system')->warning('Failed to compress log file', [
                'file' => $filePath,
                'output' => $output,
            ]);
        }
    }
}
