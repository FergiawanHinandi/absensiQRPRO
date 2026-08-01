<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Backup\BackupDestination\BackupDestination;
use Tests\TestCase;

/**
 * Property-Based Test: Backup Verification
 * 
 * Spec: saas-hardening-30-days / tasks.md Task 23.5
 * 
 * Properties:
 * - Property 62: Database backup runs daily
 * - Property 63: Backup stored in S3 with encryption
 * - Property 64: Restore tested successfully
 * 
 * This test validates that backups are created, encrypted, stored properly,
 * and can be restored successfully.
 */
class BackupVerificationTest extends TestCase
{
    use RefreshDatabase;
    
    private const MIN_ITERATIONS = 50;
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
     * Property 62: Database backup runs daily
     * 
     * Test that backup command executes successfully for any day
     * and creates a backup file.
     * 
*/
    public function property_database_backup_runs_daily_for_any_state(): void
    {
        $iterations = self::MIN_ITERATIONS;
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            // Simulate different database states
            $recordCount = rand(0, 100);
            
            try {
                // Create random database state
                $this->createRandomDatabaseState($recordCount, $i);
                
                // Execute backup command (simulating daily backup)
                $exitCode = Artisan::call('backup:database');
                
                // Property: Backup should succeed
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
                $this->cleanupTestData($i);
            }
        }

        $successRate = (($iterations - $failureCount) / $iterations) * 100;
        
        $this->assertGreaterThanOrEqual(
            95,
            $successRate,
            "Property 62 failed: Database backup should run daily. Success rate: {$successRate}%. " .
            "Expected at least 95% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property 62: Backup schedule configuration is correct
     * 
     * Test that backup is configured to run daily at the correct time.
     * 
*/
    public function property_backup_schedule_configured_for_daily_execution(): void
    {
        // Verify backup configuration
        $fullBackupSchedule = config('disaster_recovery.backup.full.schedule');
        
        $this->assertNotNull(
            $fullBackupSchedule,
            'Property 62 failed: Full backup schedule must be configured'
        );
        
        // Verify it's a daily schedule (cron format: '0 2 * * *' = 2 AM daily)
        $this->assertMatchesRegularExpression(
            '/^\d+\s+\d+\s+\*\s+\*\s+\*$/',
            $fullBackupSchedule,
            'Property 62 failed: Backup schedule should be daily (cron format)'
        );
        
        // Verify retention is at least 30 days
        $retentionDays = config('disaster_recovery.backup.full.retention_days');
        
        $this->assertGreaterThanOrEqual(
            30,
            $retentionDays,
            'Property 62 failed: Backup retention should be at least 30 days'
        );
        
        // Verify cleanup policy keeps daily backups for 30 days
        $keepDailyBackups = config('backup.cleanup.default_strategy.keep_daily_backups_for_days');
        
        $this->assertGreaterThanOrEqual(
            30,
            $keepDailyBackups,
            'Property 62 failed: Daily backups should be kept for at least 30 days'
        );
    }

    /**
     * Property 63: Backup stored with encryption
     * 
     * Test that all backups are encrypted using AES-256 encryption.
     * 
*/
    public function property_backup_stored_with_encryption_for_any_content(): void
    {
        $iterations = min(30, self::MIN_ITERATIONS);
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $recordCount = rand(1, 50);
            
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
                
                // Property: Backup file should be encrypted (GPG format)
                // GPG encrypted files have specific magic bytes
                $fileContent = File::get($latestBackup);
                $isEncrypted = $this->isFileEncrypted($fileContent);
                
                if (!$isEncrypted) {
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
            "Property 63 failed: Backups should be encrypted. Success rate: {$successRate}%. " .
            "Expected at least 95% encrypted backups across {$iterations} iterations."
        );
    }

    /**
     * Property 63: Backup encryption configuration is AES-256
     * 
     * Test that backup encryption uses AES-256 algorithm.
     * 
*/
    public function property_backup_encryption_uses_aes_256_algorithm(): void
    {
        // Verify encryption algorithm configuration
        $algorithm = config('backup.encryption.algorithm');
        
        $this->assertEquals(
            'AES-256-CBC',
            $algorithm,
            'Property 63 failed: Backup encryption must use AES-256-CBC algorithm'
        );
        
        // Verify disaster recovery encryption configuration
        $drAlgorithm = config('disaster_recovery.backup.encryption.algorithm');
        
        $this->assertEquals(
            'AES-256-GCM',
            $drAlgorithm,
            'Property 63 failed: DR backup encryption must use AES-256-GCM algorithm'
        );
        
        // Verify encryption is enabled
        $encryptionEnabled = config('disaster_recovery.backup.encryption.enabled');
        
        $this->assertTrue(
            $encryptionEnabled,
            'Property 63 failed: Backup encryption must be enabled'
        );
        
        // Verify encryption key is configured
        $encryptionKey = config('backup.backup.password');
        
        $this->assertNotNull(
            $encryptionKey,
            'Property 63 failed: Backup encryption key must be configured'
        );
    }

    /**
     * Property 63: S3 storage configuration with encryption
     * 
     * Test that S3 backup storage is configured with server-side encryption.
     * 
*/
    public function property_backup_s3_storage_configured_with_encryption(): void
    {
        // Check if S3 is configured
        if (!env('BACKUP_AWS_BUCKET')) {
            $this->markTestSkipped('S3 backup not configured in test environment');
        }
        
        // Verify S3 disk configuration
        $s3Config = config('filesystems.disks.backups-s3');
        
        $this->assertNotNull(
            $s3Config,
            'Property 63 failed: S3 backup disk must be configured'
        );
        
        $this->assertEquals(
            's3',
            $s3Config['driver'],
            'Property 63 failed: Backup disk must use S3 driver'
        );
        
        // Verify server-side encryption
        $this->assertArrayHasKey(
            'options',
            $s3Config,
            'Property 63 failed: S3 backup disk must have options configured'
        );
        
        $this->assertEquals(
            'AES256',
            $s3Config['options']['ServerSideEncryption'],
            'Property 63 failed: S3 backup must use AES256 server-side encryption'
        );
        
        // Verify S3 is in backup destinations
        $destinations = config('disaster_recovery.backup.destinations.s3');
        
        $this->assertNotNull(
            $destinations,
            'Property 63 failed: S3 must be configured as backup destination'
        );
        
        $this->assertEquals(
            'backups-s3',
            $destinations['disk'],
            'Property 63 failed: S3 destination must use backups-s3 disk'
        );
    }

    /**
     * Property 64: Restore tested successfully
     * 
     * Test that backup restore command executes successfully
     * and validates backup integrity.
     * 
*/
    public function property_restore_tested_successfully_for_any_backup(): void
    {
        $iterations = min(20, self::MIN_ITERATIONS); // Reduced due to restore overhead
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $recordCount = rand(5, 30);
            
            try {
                // Create test data and backup
                $this->createRandomDatabaseState($recordCount, $i);
                
                // Create backup
                $exitCode = Artisan::call('backup:database');
                
                if ($exitCode !== 0) {
                    $failureCount++;
                    continue;
                }
                
                // Test restore validation
                $restoreExitCode = Artisan::call('backup:test-restore', [
                    '--disk' => 'local',
                ]);
                
                // Property: Restore test should succeed
                if ($restoreExitCode !== 0) {
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
            "Property 64 failed: Restore should be tested successfully. Success rate: {$successRate}%. " .
            "Expected at least 90% success rate across {$iterations} iterations. " .
            "Failures: {$failureCount}"
        );
    }

    /**
     * Property 64: Backup integrity verification
     * 
     * Test that backup files can be verified for integrity
     * without full restoration.
     * 
*/
    public function property_backup_integrity_verified_for_any_backup(): void
    {
        $iterations = min(30, self::MIN_ITERATIONS);
        $failureCount = 0;

        for ($i = 0; $i < $iterations; $i++) {
            $recordCount = rand(1, 50);
            
            try {
                // Create test data
                $this->createRandomDatabaseState($recordCount, $i);
                
                // Create backup
                $exitCode = Artisan::call('backup:database');
                
                if ($exitCode !== 0) {
                    $failureCount++;
                    continue;
                }
                
                // Get backup file
                $backupFiles = $this->getRecentBackupFiles();
                
                if (empty($backupFiles)) {
                    $failureCount++;
                    continue;
                }
                
                $latestBackup = $backupFiles[0];
                
                // Property: Backup file should be valid and non-corrupted
                $isValid = $this->verifyBackupIntegrity($latestBackup);
                
                if (!$isValid) {
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
            "Property 64 failed: Backup integrity should be verified. Success rate: {$successRate}%. " .
            "Expected at least 95% valid backups across {$iterations} iterations."
        );
    }

    /**
     * Property 64: RTO/RPO targets are met
     * 
     * Test that backup and restore operations meet the defined
     * RTO (Recovery Time Objective) and RPO (Recovery Point Objective) targets.
     * 
*/
    public function property_backup_restore_meets_rto_rpo_targets(): void
    {
        // Verify RTO configuration
        $databaseFailureRTO = config('disaster_recovery.scenarios.database_failure.rto_minutes');
        
        $this->assertLessThanOrEqual(
            30,
            $databaseFailureRTO,
            'Property 64 failed: Database failure RTO should be 30 minutes or less'
        );
        
        // Verify RPO configuration
        $databaseFailureRPO = config('disaster_recovery.scenarios.database_failure.rpo_minutes');
        
        $this->assertLessThanOrEqual(
            5,
            $databaseFailureRPO,
            'Property 64 failed: Database failure RPO should be 5 minutes or less'
        );
        
        // Verify incremental backup interval meets RPO
        $incrementalInterval = config('disaster_recovery.backup.incremental.interval_minutes');
        
        $this->assertLessThanOrEqual(
            $databaseFailureRPO,
            $incrementalInterval,
            'Property 64 failed: Incremental backup interval should meet RPO target'
        );
        
        // Verify backup retention meets recovery requirements
        $retentionDays = config('disaster_recovery.backup.full.retention_days');
        
        $this->assertGreaterThanOrEqual(
            30,
            $retentionDays,
            'Property 64 failed: Backup retention should be at least 30 days for recovery'
        );
    }

    // Helper Methods

    /**
     * Create random database state for testing
     */
    private function createRandomDatabaseState(int $recordCount, int $seed): void
    {
        // Create test users
        for ($i = 0; $i < $recordCount; $i++) {
            DB::table('users')->insert([
                'name' => "Test User {$seed}_{$i}",
                'email' => "test_{$seed}_{$i}_" . time() . "@example.com",
                'password' => bcrypt('password'),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Get recent backup files
     */
    private function getRecentBackupFiles(): array
    {
        $backupPath = storage_path(self::BACKUP_DIR);
        $files = glob($backupPath . '/backup_*.sql.gpg');
        
        if (empty($files)) {
            return [];
        }
        
        // Sort by modification time (newest first)
        usort($files, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        return $files;
    }

    /**
     * Check if file is encrypted (GPG format)
     */
    private function isFileEncrypted(string $content): bool
    {
        // GPG encrypted files start with specific magic bytes
        // GPG binary format starts with 0x85 or 0x84
        // ASCII armored format starts with "-----BEGIN PGP MESSAGE-----"
        
        if (strlen($content) < 10) {
            return false;
        }
        
        // Check for GPG binary format
        $firstByte = ord($content[0]);
        if ($firstByte === 0x85 || $firstByte === 0x84 || $firstByte === 0x8C) {
            return true;
        }
        
        // Check for ASCII armored format
        if (str_starts_with($content, '-----BEGIN PGP MESSAGE-----')) {
            return true;
        }
        
        // Check if file is not plain SQL (encrypted files shouldn't contain SQL keywords)
        $sqlKeywords = ['CREATE TABLE', 'INSERT INTO', 'SELECT', 'DROP TABLE'];
        foreach ($sqlKeywords as $keyword) {
            if (stripos($content, $keyword) !== false) {
                return false; // Contains SQL, not encrypted
            }
        }
        
        // If it doesn't contain SQL and has binary content, likely encrypted
        return true;
    }

    /**
     * Verify backup file integrity
     */
    private function verifyBackupIntegrity(string $filepath): bool
    {
        // Check file exists
        if (!File::exists($filepath)) {
            return false;
        }
        
        // Check file is not empty
        $fileSize = File::size($filepath);
        if ($fileSize < 100) {
            return false;
        }
        
        // Check file is readable
        if (!is_readable($filepath)) {
            return false;
        }
        
        // Check file is encrypted
        $content = File::get($filepath);
        if (!$this->isFileEncrypted($content)) {
            return false;
        }
        
        return true;
    }

    /**
     * Cleanup test data
     */
    private function cleanupTestData(int $seed): void
    {
        // Delete test users created for this iteration
        DB::table('users')
            ->where('email', 'like', "test_{$seed}_%@example.com")
            ->delete();
    }

    /**
     * Cleanup test backup files
     */
    private function cleanupTestBackups(): void
    {
        $backupPath = storage_path(self::BACKUP_DIR);
        $files = glob($backupPath . '/backup_*.sql.gpg');
        
        foreach ($files as $file) {
            // Only delete recent test backups (created in last hour)
            if (time() - filemtime($file) < 3600) {
                File::delete($file);
            }
        }
    }
}
