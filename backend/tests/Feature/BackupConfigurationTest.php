<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupConfigurationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that backup configuration is properly set up.
     */
    public function test_backup_configuration_exists(): void
    {
        $this->assertNotNull(config('backup.backup.name'));
        $this->assertEquals(config('app.name', 'AbsensiQRPro'), config('backup.backup.name'));
    }

    /**
     * Test that database backup is configured.
     */
    public function test_database_backup_configured(): void
    {
        $databases = config('backup.backup.source.databases');

        $this->assertIsArray($databases);
        $this->assertNotEmpty($databases);
        $this->assertContains(config('database.default'), $databases);
    }

    /**
     * Test that backup destinations include local storage.
     */
    public function test_local_backup_destination_configured(): void
    {
        $disks = config('backup.backup.destination.disks');

        $this->assertIsArray($disks);
        $this->assertContains('local', $disks);
    }

    /**
     * Test that backup encryption is configured.
     */
    public function test_backup_encryption_configured(): void
    {
        // Check if encryption password is set (in test environment, it might be null)
        $password = config('backup.backup.password');

        // In production, this should be set
        if (app()->environment('production')) {
            $this->assertNotNull($password, 'Backup encryption password must be set in production');
        }
    }

    /**
     * Test that backup disks are configured
     */
    public function test_backup_disks_configured(): void
    {
        $disks = config('backup.backup.destination.disks');

        $this->assertIsArray($disks);
        $this->assertContains('local', $disks, 'Local disk must be configured for backups');
        
        // S3 disk should be configured if BACKUP_AWS_BUCKET is set
        if (env('BACKUP_AWS_BUCKET')) {
            $this->assertContains('backups-s3', $disks, 'S3 disk should be configured when BACKUP_AWS_BUCKET is set');
        }
    }

    /**
     * Test that backup encryption algorithm is AES-256
     */
    public function test_backup_uses_aes_256_encryption(): void
    {
        $algorithm = config('backup.encryption.algorithm');
        
        $this->assertEquals('AES-256-CBC', $algorithm, 'Backup must use AES-256-CBC encryption');
    }

    /**
     * Test that backup notifications are configured
     */
    public function test_backup_notifications_configured(): void
    {
        $notifications = config('backup.notifications.notifications');

        $this->assertIsArray($notifications);
        $this->assertArrayHasKey(\Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class, $notifications);
        $this->assertArrayHasKey(\Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class, $notifications);
    }

    /**
     * Test that backup monitoring is configured
     */
    public function test_backup_monitoring_configured(): void
    {
        $monitors = config('backup.monitor_backups');

        $this->assertIsArray($monitors);
        $this->assertNotEmpty($monitors);
        
        $monitor = $monitors[0];
        $this->assertArrayHasKey('name', $monitor);
        $this->assertArrayHasKey('disks', $monitor);
        $this->assertArrayHasKey('health_checks', $monitor);
    }

    /**
     * Test that S3 backup disk has encryption enabled
     */
    public function test_s3_backup_disk_has_encryption(): void
    {
        if (!env('BACKUP_AWS_BUCKET')) {
            $this->markTestSkipped('S3 backup not configured');
        }

        $s3Config = config('filesystems.disks.backups-s3');

        $this->assertEquals('s3', $s3Config['driver']);
        $this->assertEquals('private', $s3Config['visibility']);
        $this->assertTrue($s3Config['throw'], 'S3 backup disk should throw exceptions on failure');
        
        // Verify server-side encryption
        $this->assertArrayHasKey('options', $s3Config);
        $this->assertEquals('AES256', $s3Config['options']['ServerSideEncryption']);
    }

    /**
     * Test that backup database dump uses compression
     */
    public function test_backup_database_dump_compression(): void
    {
        // On production/Linux, compression should be enabled
        if (env('BACKUP_USE_GZIP', false)) {
            $compressor = config('backup.backup.database_dump_compressor');
            $this->assertNotNull($compressor, 'Database dump compression should be enabled');
        }
    }

    /**
     * Test that backup retry configuration is set
     */
    public function test_backup_retry_configuration(): void
    {
        $tries = config('backup.backup.tries');
        $retryDelay = config('backup.backup.retry_delay');

        $this->assertEquals(3, $tries, 'Backup should retry 3 times on failure');
        $this->assertEquals(30, $retryDelay, 'Retry delay should be 30 seconds');
    }

    /**
     * Test that backup cleanup retry configuration is set
     */
    public function test_backup_cleanup_retry_configuration(): void
    {
        $tries = config('backup.cleanup.tries');
        $retryDelay = config('backup.cleanup.retry_delay');

        $this->assertEquals(2, $tries, 'Cleanup should retry 2 times on failure');
        $this->assertEquals(15, $retryDelay, 'Cleanup retry delay should be 15 seconds');
    }

    /**
     * Test that backup excludes unnecessary files
     */
    public function test_backup_excludes_unnecessary_files(): void
    {
        $excludes = config('backup.backup.source.files.exclude');

        $this->assertIsArray($excludes);
        $this->assertContains(base_path('vendor'), $excludes, 'Vendor directory should be excluded');
        $this->assertContains(base_path('node_modules'), $excludes, 'Node modules should be excluded');
        $this->assertContains(storage_path('logs'), $excludes, 'Logs should be excluded');
        $this->assertContains(storage_path('framework/cache'), $excludes, 'Cache should be excluded');
    }

    /**
     * Test that backup includes essential files
     */
    public function test_backup_includes_essential_files(): void
    {
        $includes = config('backup.backup.source.files.include');

        $this->assertIsArray($includes);
        $this->assertContains(storage_path('app/public'), $includes, 'Public uploads should be included');
        $this->assertContains(storage_path('app/security-reports'), $includes, 'Security reports should be included');
    }

    /**
     * Test that backup uses correct database connection
     */
    public function test_backup_uses_correct_database_connection(): void
    {
        $databases = config('backup.backup.source.databases');

        $this->assertIsArray($databases);
        $this->assertContains(env('DB_CONNECTION', 'pgsql'), $databases, 'Backup should use the configured database connection');
    }

    /**
     * Test backup configuration command exists
     */
    public function test_backup_configuration_command_exists(): void
    {
        $commands = Artisan::all();
        
        $this->assertArrayHasKey('backup:configure', $commands, 'backup:configure command must exist');
    }
}
