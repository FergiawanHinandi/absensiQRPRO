<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;
use Carbon\Carbon;

class AtomicRollbackService
{
    private $rollbackId;
    private $rollbackSteps = [];
    private $isRollingBack = false;

    public function __construct()
    {
        $this->rollbackId = 'rollback_' . uniqid() . '_' . time();
    }

    /**
     * Execute atomic rollback with fail-safe checkpoints
     */
    public function executeRollback(string $type, string $backupIdentifier = null): array
    {
        if ($this->isRollingBack) {
            throw new \Exception('Rollback already in progress');
        }

        $this->isRollingBack = true;
        $this->logStep('START', "Starting {$type} rollback", $backupIdentifier);

        try {
            switch ($type) {
                case 'database':
                    return $this->rollbackDatabase($backupIdentifier);
                case 'storage':
                    return $this->rollbackStorage($backupIdentifier);
                case 'full':
                    return $this->rollbackFullSystem($backupIdentifier);
                default:
                    throw new \InvalidArgumentException("Invalid rollback type: {$type}");
            }
        } catch (\Exception $e) {
            $this->logStep('ERROR', 'Rollback failed: ' . $e->getMessage());
            $this->isRollingBack = false;
            throw $e;
        }

        $this->isRollingBack = false;
    }

    /**
     * Database-only rollback
     */
    private function rollbackDatabase(string $backupIdentifier): array
    {
        $this->logStep('DB_START', 'Starting database rollback');

        // Checkpoint 1: Verify backup exists
        if (!$this->verifyDatabaseBackup($backupIdentifier)) {
            throw new \Exception('Database backup not found or corrupted');
        }
        $this->logStep('DB_CHECKPOINT_1', 'Database backup verified');

        // Checkpoint 2: Create current state backup before rollback
        $preRollbackBackup = $this->createDatabaseBackup('pre_rollback_' . $this->rollbackId);
        $this->logStep('DB_CHECKPOINT_2', 'Pre-rollback backup created: ' . $preRollbackBackup);

        // Checkpoint 3: Execute database restore
        try {
            DB::beginTransaction();
            
            // Disable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            
            // Restore from backup
            $restoreResult = $this->restoreDatabaseFromBackup($backupIdentifier);
            
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            DB::commit();
            $this->logStep('DB_SUCCESS', 'Database restored successfully');
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            // Rollback failed - attempt to restore pre-rollback state
            $this->logStep('DB_RESTORE_FAILED', 'Database restore failed, attempting recovery');
            
            try {
                $this->restoreDatabaseFromBackup($preRollbackBackup);
                $this->logStep('DB_RECOVERY_SUCCESS', 'Successfully restored pre-rollback state');
            } catch (\Exception $recoveryException) {
                $this->logStep('DB_RECOVERY_FAILED', 'CRITICAL: Recovery failed - ' . $recoveryException->getMessage());
            }
            
            throw new \Exception('Database rollback failed: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'rollback_id' => $this->rollbackId,
            'type' => 'database',
            'backup_used' => $backupIdentifier,
            'pre_rollback_backup' => $preRollbackBackup,
            'steps' => $this->rollbackSteps
        ];
    }

    /**
     * Storage-only rollback
     */
    private function rollbackStorage(string $backupIdentifier): array
    {
        $this->logStep('STORAGE_START', 'Starting storage rollback');

        // Checkpoint 1: Verify storage backup exists
        if (!$this->verifyStorageBackup($backupIdentifier)) {
            throw new \Exception('Storage backup not found or corrupted');
        }
        $this->logStep('STORAGE_CHECKPOINT_1', 'Storage backup verified');

        // Checkpoint 2: Create current state backup
        $preRollbackBackup = $this->createStorageBackup('pre_rollback_' . $this->rollbackId);
        $this->logStep('STORAGE_CHECKPOINT_2', 'Pre-rollback storage backup created: ' . $preRollbackBackup);

        // Checkpoint 3: Execute storage restore
        try {
            $restoreResult = $this->restoreStorageFromBackup($backupIdentifier);
            $this->logStep('STORAGE_SUCCESS', 'Storage restored successfully');
            
        } catch (\Exception $e) {
            // Storage restore failed - attempt recovery
            $this->logStep('STORAGE_RESTORE_FAILED', 'Storage restore failed, attempting recovery');
            
            try {
                $this->restoreStorageFromBackup($preRollbackBackup);
                $this->logStep('STORAGE_RECOVERY_SUCCESS', 'Successfully restored pre-rollback storage state');
            } catch (\Exception $recoveryException) {
                $this->logStep('STORAGE_RECOVERY_FAILED', 'CRITICAL: Storage recovery failed - ' . $recoveryException->getMessage());
            }
            
            throw new \Exception('Storage rollback failed: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'rollback_id' => $this->rollbackId,
            'type' => 'storage',
            'backup_used' => $backupIdentifier,
            'pre_rollback_backup' => $preRollbackBackup,
            'steps' => $this->rollbackSteps
        ];
    }

    /**
     * Full system rollback with atomic operations
     */
    private function rollbackFullSystem(string $backupIdentifier): array
    {
        $this->logStep('FULL_START', 'Starting full system rollback');

        // Checkpoint 1: Verify both backups exist
        if (!$this->verifyDatabaseBackup($backupIdentifier) || !$this->verifyStorageBackup($backupIdentifier)) {
            throw new \Exception('One or more system backups not found or corrupted');
        }
        $this->logStep('FULL_CHECKPOINT_1', 'Both database and storage backups verified');

        // Checkpoint 2: Create pre-rollback backups
        $preRollbackDbBackup = $this->createDatabaseBackup('pre_rollback_db_' . $this->rollbackId);
        $preRollbackStorageBackup = $this->createStorageBackup('pre_rollback_storage_' . $this->rollbackId);
        $this->logStep('FULL_CHECKPOINT_2', 'Pre-rollback backups created');

        // Checkpoint 3: Atomic database rollback first
        try {
            DB::beginTransaction();
            
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $this->restoreDatabaseFromBackup($backupIdentifier);
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            
            DB::commit();
            $this->logStep('FULL_DB_SUCCESS', 'Database rollback successful');
            
        } catch (\Exception $e) {
            DB::rollBack();
            $this->logStep('FULL_DB_FAILED', 'Database rollback failed - cancelling full rollback');
            throw new \Exception('Full system rollback cancelled due to database failure: ' . $e->getMessage());
        }

        // Checkpoint 4: Storage rollback
        try {
            $this->restoreStorageFromBackup($backupIdentifier);
            $this->logStep('FULL_STORAGE_SUCCESS', 'Storage rollback successful');
            
        } catch (\Exception $e) {
            $this->logStep('FULL_STORAGE_FAILED', 'Storage rollback failed - reverting database changes');
            
            // Critical: Revert database changes
            try {
                DB::beginTransaction();
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
                $this->restoreDatabaseFromBackup($preRollbackDbBackup);
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
                DB::commit();
                $this->logStep('FULL_DB_REVERT_SUCCESS', 'Database changes successfully reverted');
            } catch (\Exception $revertException) {
                $this->logStep('FULL_DB_REVERT_FAILED', 'CRITICAL: Failed to revert database changes - ' . $revertException->getMessage());
            }
            
            throw new \Exception('Full system rollback failed due to storage failure: ' . $e->getMessage());
        }

        // Checkpoint 5: Final verification
        if (!$this->verifySystemIntegrity()) {
            $this->logStep('FULL_INTEGRITY_FAILED', 'System integrity check failed');
            throw new \Exception('System integrity compromised after rollback');
        }

        $this->logStep('FULL_SUCCESS', 'Full system rollback completed successfully');

        return [
            'success' => true,
            'rollback_id' => $this->rollbackId,
            'type' => 'full_system',
            'backup_used' => $backupIdentifier,
            'pre_rollback_backups' => [
                'database' => $preRollbackDbBackup,
                'storage' => $preRollbackStorageBackup
            ],
            'steps' => $this->rollbackSteps
        ];
    }

    /**
     * Log rollback steps
     */
    private function logStep(string $step, string $message, string $details = null): void
    {
        $logEntry = [
            'timestamp' => Carbon::now()->toISOString(),
            'step' => $step,
            'message' => $message,
            'details' => $details,
            'rollback_id' => $this->rollbackId
        ];

        $this->rollbackSteps[] = $logEntry;

        // Log to Laravel's activity log
        activity()
            ->causedBy(auth()->user())
            ->performedOn(new \stdClass())
            ->withProperties($logEntry)
            ->log("Atomic Rollback: {$step} - {$message}");

        // Log to file
        Log::channel('rollback')->info("Atomic Rollback [{$this->rollbackId}] {$step}: {$message}", $logEntry);
    }

    /**
     * Verify database backup exists and is valid
     */
    private function verifyDatabaseBackup(string $backupIdentifier): bool
    {
        $backupPath = storage_path("app/backups/database/{$backupIdentifier}.sql");
        
        if (!file_exists($backupPath)) {
            return false;
        }

        // Basic integrity check
        $fileSize = filesize($backupPath);
        return $fileSize > 0;
    }

    /**
     * Verify storage backup exists and is valid
     */
    private function verifyStorageBackup(string $backupIdentifier): bool
    {
        $backupPath = storage_path("app/backups/storage/{$backupIdentifier}.zip");
        
        if (!file_exists($backupPath)) {
            return false;
        }

        // Check if it's a valid zip file
        $zip = new \ZipArchive();
        $result = $zip->open($backupPath);
        $zip->close();
        
        return $result === true;
    }

    /**
     * Create database backup
     */
    private function createDatabaseBackup(string $identifier): string
    {
        $backupPath = storage_path("app/backups/database/{$identifier}.sql");
        $dbConfig = config('database.connections.mysql');
        
        $command = sprintf(
            'mysqldump -h%s -u%s -p%s %s > %s',
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database'],
            $backupPath
        );

        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('Failed to create database backup');
        }

        return $identifier;
    }

    /**
     * Create storage backup
     */
    private function createStorageBackup(string $identifier): string
    {
        $backupPath = storage_path("app/backups/storage/{$identifier}.zip");
        $storagePath = storage_path('app/public');
        
        $zip = new \ZipArchive();
        if ($zip->open($backupPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($storagePath),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($storagePath) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }

            $zip->close();
        } else {
            throw new \Exception('Failed to create storage backup');
        }

        return $identifier;
    }

    /**
     * Restore database from backup
     */
    private function restoreDatabaseFromBackup(string $backupIdentifier): void
    {
        $backupPath = storage_path("app/backups/database/{$backupIdentifier}.sql");
        $dbConfig = config('database.connections.mysql');
        
        $command = sprintf(
            'mysql -h%s -u%s -p%s %s < %s',
            $dbConfig['host'],
            $dbConfig['username'],
            $dbConfig['password'],
            $dbConfig['database'],
            $backupPath
        );

        exec($command, $output, $returnCode);
        
        if ($returnCode !== 0) {
            throw new \Exception('Failed to restore database from backup');
        }
    }

    /**
     * Restore storage from backup
     */
    private function restoreStorageFromBackup(string $backupIdentifier): void
    {
        $backupPath = storage_path("app/backups/storage/{$backupIdentifier}.zip");
        $storagePath = storage_path('app/public');
        
        // Clear existing storage (except .gitkeep)
        $files = glob($storagePath . '/*');
        foreach ($files as $file) {
            if (basename($file) !== '.gitkeep') {
                if (is_dir($file)) {
                    $this->deleteDirectory($file);
                } else {
                    unlink($file);
                }
            }
        }

        // Extract backup
        $zip = new \ZipArchive();
        if ($zip->open($backupPath) === true) {
            $zip->extractTo($storagePath);
            $zip->close();
        } else {
            throw new \Exception('Failed to extract storage backup');
        }
    }

    /**
     * Verify system integrity after rollback
     */
    private function verifySystemIntegrity(): bool
    {
        try {
            // Check database connectivity
            DB::connection()->getPdo();
            
            // Check basic tables exist
            $tables = DB::select('SHOW TABLES');
            if (empty($tables)) {
                return false;
            }

            // Check storage directories exist
            $storagePath = storage_path('app/public');
            if (!is_dir($storagePath)) {
                return false;
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Recursively delete directory
     */
    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    /**
     * Get rollback history
     */
    public function getRollbackHistory(): array
    {
        return Activity::where('description', 'like', 'Atomic Rollback:%')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'rollback_id' => $activity->properties['rollback_id'] ?? null,
                    'step' => $activity->properties['step'] ?? null,
                    'message' => $activity->properties['message'] ?? null,
                    'timestamp' => $activity->created_at,
                    'user' => $activity->causer ? $activity->causer->name : null
                ];
            })
            ->toArray();
    }
}
