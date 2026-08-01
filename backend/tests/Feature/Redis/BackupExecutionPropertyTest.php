<?php

namespace Tests\Feature\Redis;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Property-Based Test: Automated Backup Execution
 * 
 * Feature: redis-high-availability
 * Property 29: Automated backup execution
 * Validates: Requirements 7.1
 * 
 * This test validates that for any day, automated backups of all persistent
 * data should be performed successfully.
 * 
 * Property: For any day, automated backups of all persistent data should be
 * performed without errors and with verifiable integrity.
 */
class BackupExecutionPropertyTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 100;
    private const BACKUP_DIR = 'app/backups';
    
    protected function setUp(): void
    {
        parent::setUp();
        
        // Skip if not using PostgreSQL
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('This test requires PostgreSQL');
        }
        
        // Ensure backup directory exists
        $backupPath = storage_path(self::BACKUP_DIR);
        if (!File::exists($backupPath)) {
            File::makeDirectory($backupPath, 0755, true);
        }
        
        // Ensure backup encryption key is set
        if (empty(config('backup.backup.password'))) {
            config(['backup.backup.password' => 'test-encryption-key-for-property-testing']);
        }
    }
    
    protected function tearDown(): void
    {
        // Cleanup test backup files
        $this->cleanupTestBackups();
        
        parent::tearDown();
    }

    /**
     * Property Test: Backup execution succeeds for any database state
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that backup execution succeeds regardless of
     * the current database state (empty, small, or large datasets).
     * 
*/
    public function property_backup_execution_succeeds_for_any_database_state(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Generate random database state
            $recordCount = rand(0, 50);
            
            try {
                // Create random data
                $this->createRandomDatabaseState($recordCount, $i);
                
                // Execute backup
                $exitCode = Artisan::call('backup:database');
                
                // Property: Backup should succeed (exit code 0)
                if ($exitCode !== 0) {
                    $failureCount++;
                    continue;
                }
                
                // Verify backup file was created
                $backupFiles = $this->getRecentBackupFiles();
                
                if (empty($backupFiles)) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                // Cleanup test data
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Backup execution property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property Test: Backup files are non-empty for any database content
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that backup files always contain data and are
     * not empty or corrupted.
     * 
*/
    public function property_backup_files_are_non_empty_for_any_database_content(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS); // Reduced due to file I/O
        $failureCount = 0;
        $minFileSize = 100; // Minimum 100 bytes for valid backup

        for ($i = 0; $i < $iterations; $i++) {
            $recordCount = rand(1, 20);
            
            try {
                // Create test data
                $this->createRandomDatabaseState($recordCount, $i);
                
                // Execute backup
                $exitCode = Artisan::call('backup:database');
                
                if ($exitCode !== 0) {
                    $failureCount++;
                    continue;
                }
                
                // Get most recent backup file
                $backupFiles = $this->getRecentBackupFiles();
                
                if (empty($backupFiles)) {
                    $failureCount++;
                    continue;
                }
                
                $latestBackup = $backupFiles[0];
                $fileSize = File::size($latestBackup);
                
                // Property: Backup file should be non-empty
                if ($fileSize < $minFileSize) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Non-empty backup property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to have file size > {$minFileSize} bytes across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Backup execution creates audit log for any execution
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that every backup execution creates an audit log
     * entry regardless of success or failure.
     * 
*/
    public function property_backup_execution_creates_audit_log_for_any_execution(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Clear audit logs
                DB::table('audit_logs')->where('action', 'LIKE', '%backup%')->delete();
                
                // Execute backup
                Artisan::call('backup:database');
                
                // Property: Audit log should exist
                $auditLog = DB::table('audit_logs')
                    ->where('action', 'LIKE', '%backup%')
                    ->latest('created_at')
                    ->first();
                
                if ($auditLog === null) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Audit log property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to create audit logs across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Backup cleanup maintains retention policy for any file count
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that backup cleanup correctly maintains the 7-day
     * retention policy regardless of the number of backup files.
     * 
*/
    public function property_backup_cleanup_maintains_retention_policy_for_any_file_count(): void
    {
        $iterations = min(50, self::MIN_ITERATIONS);
        $failureCount = 0;
        $retentionDays = 7;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Create random number of old backup files (8-15 days old)
                $oldFileCount = rand(3, 10);
                $this->createOldBackupFiles($oldFileCount, rand(8, 15));
                
                // Create recent backup files (1-6 days old)
                $recentFileCount = rand(2, 5);
                $this->createOldBackupFiles($recentFileCount, rand(1, 6));
                
                // Execute backup (which includes cleanup)
                Artisan::call('backup:database');
                
                // Property: Only files within retention period should remain
                $remainingFiles = $this->getBackupFilesOlderThan($retentionDays);
                
                if (count($remainingFiles) > 0) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestBackups();
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Retention policy property failed. Success rate: {$successRate}%. " .
            "Expected at least 95% to maintain {$retentionDays}-day retention across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Backup execution handles concurrent requests gracefully
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that concurrent backup executions are handled
     * gracefully without corruption or conflicts.
     * 
*/
    public function property_backup_execution_handles_concurrent_requests_gracefully(): void
    {
        $iterations = min(30, self::MIN_ITERATIONS); // Reduced due to concurrency overhead
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Note: True concurrency is difficult in PHPUnit
                // We simulate by executing backup twice in quick succession
                
                $exitCode1 = Artisan::call('backup:database');
                $exitCode2 = Artisan::call('backup:database');
                
                // Property: At least one should succeed
                if ($exitCode1 !== 0 && $exitCode2 !== 0) {
                    $failureCount++;
                }
                
                // Verify no corrupted files
                $backupFiles = $this->getRecentBackupFiles();
                foreach ($backupFiles as $file) {
                    if (File::size($file) === 0) {
                        $failureCount++;
                        break;
                    }
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Concurrent execution property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to handle concurrency gracefully across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Backup filename format is consistent for any execution time
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that backup filenames follow a consistent format
     * regardless of when the backup is executed.
     * 
*/
    public function property_backup_filename_format_is_consistent_for_any_execution_time(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;
        $filenamePattern = '/^backup_\d{4}-\d{2}-\d{2}_\d{6}\.sql\.gpg$/';

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Execute backup
                $exitCode = Artisan::call('backup:database');
                
                if ($exitCode !== 0) {
                    $failureCount++;
                    continue;
                }
                
                // Get most recent backup file
                $backupFiles = $this->getRecentBackupFiles();
                
                if (empty($backupFiles)) {
                    $failureCount++;
                    continue;
                }
                
                $latestBackup = basename($backupFiles[0]);
                
                // Property: Filename should match expected pattern
                if (!preg_match($filenamePattern, $latestBackup)) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Filename format property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to match pattern across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Backup execution completes within acceptable time for any data size
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that backup execution completes within a reasonable
     * time regardless of database size.
     * 
*/
    public function property_backup_execution_completes_within_acceptable_time_for_any_data_size(): void
    {
        $iterations = min(30, self::MIN_ITERATIONS);
        $failureCount = 0;
        $maxTimeSeconds = 60; // 60 seconds max for test database

        for ($i = 0; $i < $iterations; $i++) {
            $recordCount = rand(10, 100);
            
            try {
                // Create test data
                $this->createRandomDatabaseState($recordCount, $i);
                
                // Measure backup time
                $startTime = microtime(true);
                $exitCode = Artisan::call('backup:database');
                $duration = microtime(true) - $startTime;
                
                // Property: Backup should complete within acceptable time
                if ($exitCode !== 0 || $duration > $maxTimeSeconds) {
                    $failureCount++;
                }
                
            } catch (\Exception $e) {
                $failureCount++;
            } finally {
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            90,
            $successRate,
            "Backup timing property failed. Success rate: {$successRate}%. " .
            "Expected at least 90% to complete within {$maxTimeSeconds}s across {$iterations} iterations."
        );
    }

    /**
     * Property Test: Backup execution handles missing encryption key gracefully
     * 
     * **Validates: Requirements 7.1**
     * 
     * This test verifies that backup execution fails gracefully when
     * encryption key is missing, without creating corrupted files.
     * 
*/
    public function property_backup_execution_handles_missing_encryption_key_gracefully(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            try {
                // Temporarily remove encryption key
                $originalKey = config('backup.backup.password');
                config(['backup.backup.password' => null]);
                
                // Execute backup (should fail gracefully)
                $exitCode = Artisan::call('backup:database');
                
                // Restore encryption key
                config(['backup.backup.password' => $originalKey]);
                
                // Property: Should fail with non-zero exit code
                if ($exitCode === 0) {
                    $failureCount++;
                }
                
                // Verify no corrupted backup files were created
                $backupFiles = $this->getRecentBackupFiles();
                foreach ($backupFiles as $file) {
                    if (File::size($file) === 0) {
                        $failureCount++;
                        break;
                    }
                }
                
            } catch (\Exception $e) {
                // Restore encryption key on exception
                config(['backup.backup.password' => 'test-encryption-key-for-property-testing']);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            99,
            $successRate,
            "Graceful failure property failed. Success rate: {$successRate}%. " .
            "Expected at least 99% to fail gracefully across {$iterations} iterations."
        );
    }

    // ========================================================================
    // Helper Methods
    // ========================================================================

    /**
     * Create random database state for testing
     * 
     * @param int $recordCount Number of records to create
     * @param int $seed Seed for randomization
     */
    private function createRandomDatabaseState(int $recordCount, int $seed): void
    {
        for ($i = 0; $i < $recordCount; $i++) {
            $schoolId = 800000 + ($seed * 1000) + $i;
            
            DB::table('schools')->insert([
                'id' => $schoolId,
                'name' => "Test School {$seed}_{$i}",
                'address' => "Test Address {$seed}_{$i}",
                'phone' => '08' . str_pad($seed * 100 + $i, 10, '0'),
                'email' => "school{$seed}_{$i}@test.com",
                'timezone' => 'Asia/Jakarta',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Cleanup test data
     * 
     * @param int $seed Seed used for data creation
     */
    private function cleanupTestData(int $seed): void
    {
        $minId = 800000 + ($seed * 1000);
        $maxId = 800000 + (($seed + 1) * 1000);
        
        DB::table('schools')
            ->whereBetween('id', [$minId, $maxId])
            ->delete();
    }

    /**
     * Get recent backup files
     * 
     * @param int $limit Maximum number of files to return
     * @return array Array of file paths
     */
    private function getRecentBackupFiles(int $limit = 10): array
    {
        $backupPath = storage_path(self::BACKUP_DIR);
        
        if (!File::exists($backupPath)) {
            return [];
        }
        
        $files = File::glob($backupPath . '/backup_*.sql.gpg');
        
        // Sort by modification time (newest first)
        usort($files, function ($a, $b) {
            return File::lastModified($b) - File::lastModified($a);
        });
        
        return array_slice($files, 0, $limit);
    }

    /**
     * Create old backup files for testing retention policy
     * 
     * @param int $count Number of files to create
     * @param int $daysOld Age of files in days
     */
    private function createOldBackupFiles(int $count, int $daysOld): void
    {
        $backupPath = storage_path(self::BACKUP_DIR);
        
        for ($i = 0; $i < $count; $i++) {
            $timestamp = now()->subDays($daysOld)->format('Y-m-d_His');
            $filename = "backup_{$timestamp}_test_{$i}.sql.gpg";
            $filepath = $backupPath . '/' . $filename;
            
            // Create dummy file
            File::put($filepath, 'test backup content');
            
            // Set file modification time
            touch($filepath, now()->subDays($daysOld)->timestamp);
        }
    }

    /**
     * Get backup files older than specified days
     * 
     * @param int $days Number of days
     * @return array Array of file paths
     */
    private function getBackupFilesOlderThan(int $days): array
    {
        $backupPath = storage_path(self::BACKUP_DIR);
        
        if (!File::exists($backupPath)) {
            return [];
        }
        
        $files = File::glob($backupPath . '/backup_*.sql.gpg');
        $cutoffTime = now()->subDays($days)->timestamp;
        $oldFiles = [];
        
        foreach ($files as $file) {
            if (File::lastModified($file) < $cutoffTime) {
                $oldFiles[] = $file;
            }
        }
        
        return $oldFiles;
    }

    /**
     * Cleanup all test backup files
     */
    private function cleanupTestBackups(): void
    {
        $backupPath = storage_path(self::BACKUP_DIR);
        
        if (!File::exists($backupPath)) {
            return;
        }
        
        $files = File::glob($backupPath . '/backup_*.sql.gpg');
        
        foreach ($files as $file) {
            File::delete($file);
        }
    }
}
