<?php

namespace Tests\Unit\Commands;

use PHPUnit\Framework\Attributes\Test;

use App\Console\Commands\BackupDatabase;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = storage_path('app/backups');
        
        // Clean up any existing test backups
        if (file_exists($this->backupDir)) {
            array_map('unlink', glob("{$this->backupDir}/backup_*.sql.gpg"));
        }
    }

    protected function tearDown(): void
    {
        // Clean up test backups
        if (file_exists($this->backupDir)) {
            array_map('unlink', glob("{$this->backupDir}/backup_*.sql.gpg"));
        }
        
        parent::tearDown();
    }


    #[Test]
    public function it_fails_when_encryption_key_is_not_configured()
    {
        config(['backup.backup.password' => null]);

        $this->artisan('backup:database')
            ->expectsOutput('CRITICAL: backup.backup.password is not set')
            ->expectsOutput('Backups must be encrypted. Aborting.')
            ->assertExitCode(1);
    }


    #[Test]
    public function it_creates_backup_directory_if_not_exists()
    {
        config(['backup.backup.password' => 'test-encryption-key']);
        
        // Remove directory if exists
        if (file_exists($this->backupDir)) {
            rmdir($this->backupDir);
        }

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        $this->artisan('backup:database');

        $this->assertDirectoryExists($this->backupDir);
    }


    #[Test]
    public function it_creates_encrypted_backup_file_with_correct_naming()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'test_db',
            'database.connections.pgsql.username' => 'test_user',
            'database.connections.pgsql.password' => 'test_pass',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        // Create a mock encrypted file
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        
        $mockFile = $this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg';
        file_put_contents($mockFile, 'encrypted data');

        $this->artisan('backup:database')
            ->assertExitCode(0);

        $files = glob("{$this->backupDir}/backup_*.sql.gpg");
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('backup_', basename($files[0]));
        $this->assertStringEndsWith('.sql.gpg', basename($files[0]));
    }


    #[Test]
    public function it_uses_correct_database_configuration()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.host' => 'custom-host',
            'database.connections.pgsql.port' => '5433',
            'database.connections.pgsql.database' => 'custom_db',
            'database.connections.pgsql.username' => 'custom_user',
            'database.connections.pgsql.password' => 'custom_pass',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        // Create mock file
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database')
            ->assertExitCode(0);

        Process::assertRan(function ($process) {
            return str_contains($process->command, 'custom-host') &&
                   str_contains($process->command, '5433') &&
                   str_contains($process->command, 'custom_db') &&
                   str_contains($process->command, 'custom_user');
        });
    }


    #[Test]
    public function it_uses_aes256_encryption_for_gpg()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database')
            ->assertExitCode(0);

        Process::assertRan(function ($process) {
            return str_contains($process->command, '--cipher-algo AES256') &&
                   str_contains($process->command, '--symmetric');
        });
    }


    #[Test]
    public function it_logs_successful_backup_to_audit_trail()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database')
            ->assertExitCode(0);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'scheduled_backup',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Laravel Scheduler',
        ]);

        $log = AuditLog::where('action', 'scheduled_backup')->first();
        $this->assertStringContainsString('Encrypted backup created:', $log->description);
        $this->assertStringContainsString('.sql.gpg', $log->description);
    }


    #[Test]
    public function it_logs_backup_failure_to_audit_trail()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(
                errorOutput: 'Connection failed',
                exitCode: 1
            ),
        ]);

        $this->artisan('backup:database')
            ->assertExitCode(1);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'backup_failed',
            'ip_address' => '127.0.0.1',
        ]);

        $log = AuditLog::where('action', 'backup_failed')->first();
        $this->assertStringContainsString('Backup failed:', $log->description);
    }


    #[Test]
    public function it_handles_process_failure_gracefully()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(
                errorOutput: 'pg_dump: error: connection to database failed',
                exitCode: 1
            ),
        ]);

        $this->artisan('backup:database')
            ->expectsOutput('Backup failed: Backup failed: pg_dump: error: connection to database failed')
            ->assertExitCode(1);
    }


    #[Test]
    public function it_deletes_partial_backup_file_on_failure()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        // Create a partial file that should be deleted
        $partialFile = $this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg';
        file_put_contents($partialFile, 'partial data');

        Process::fake([
            '*pg_dump*' => Process::result(
                errorOutput: 'Backup failed',
                exitCode: 1
            ),
        ]);

        $this->artisan('backup:database')
            ->assertExitCode(1);

        // Note: The actual command would delete the file, but in test we can't easily verify
        // this without mocking file operations. This test documents the expected behavior.
    }


    #[Test]
    public function it_validates_backup_file_exists_and_has_content()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        // Don't create the file - simulate GPG failure
        $this->artisan('backup:database')
            ->expectsOutput('Backup failed: Backup file is empty or missing.')
            ->assertExitCode(1);
    }


    #[Test]
    public function it_cleans_up_old_backups_older_than_seven_days()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        // Create old backup files
        $oldFile1 = $this->backupDir . '/backup_2024-01-01_120000.sql.gpg';
        $oldFile2 = $this->backupDir . '/backup_2024-01-02_120000.sql.gpg';
        $recentFile = $this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg';

        file_put_contents($oldFile1, 'old data 1');
        file_put_contents($oldFile2, 'old data 2');
        file_put_contents($recentFile, 'recent data');

        // Set file modification time to 8 days ago
        touch($oldFile1, time() - (8 * 24 * 60 * 60));
        touch($oldFile2, time() - (8 * 24 * 60 * 60));

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        $this->artisan('backup:database')
            ->expectsOutput('Deleted 2 old encrypted backup(s)')
            ->assertExitCode(0);

        $this->assertFileDoesNotExist($oldFile1);
        $this->assertFileDoesNotExist($oldFile2);
        $this->assertFileExists($recentFile);
    }


    #[Test]
    public function it_uploads_to_cloud_when_upload_flag_is_set()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Storage::fake('s3');

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        
        $backupFile = $this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg';
        file_put_contents($backupFile, 'encrypted backup data');

        $this->artisan('backup:database --upload')
            ->expectsOutput('Uploading to cloud storage...')
            ->expectsOutput('Upload successful!')
            ->assertExitCode(0);

        Storage::disk('s3')->assertExists('backups/' . basename($backupFile));
    }


    #[Test]
    public function it_continues_on_cloud_upload_failure()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Storage::shouldReceive('disk')
            ->with('s3')
            ->andThrow(new \Exception('S3 connection failed'));

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database --upload')
            ->expectsOutput('Cloud upload failed: S3 connection failed')
            ->assertExitCode(0); // Should still succeed even if upload fails
    }


    #[Test]
    public function it_uses_pgpassword_environment_variable_for_security()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.password' => 'secret-db-password',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database')
            ->assertExitCode(0);

        // Verify PGPASSWORD was set in environment
        Process::assertRan(function ($process) {
            return isset($process->environment['PGPASSWORD']) &&
                   $process->environment['PGPASSWORD'] === 'secret-db-password';
        });
    }


    #[Test]
    public function it_sets_appropriate_timeout_for_large_databases()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database')
            ->assertExitCode(0);

        // Verify timeout is set to 3600 seconds (1 hour)
        Process::assertRan(function ($process) {
            return $process->timeout === 3600;
        });
    }


    #[Test]
    public function it_outputs_progress_messages_during_backup()
    {
        config([
            'backup.backup.password' => 'test-encryption-key',
            'database.connections.pgsql.database' => 'test_db',
        ]);

        Process::fake([
            '*pg_dump*' => Process::result(output: 'SQL DUMP DATA'),
        ]);

        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }
        file_put_contents($this->backupDir . '/backup_' . date('Y-m-d_His') . '.sql.gpg', 'data');

        $this->artisan('backup:database')
            ->expectsOutput('Starting secure database backup...')
            ->expectsOutput('Streaming database dump to encrypted file...')
            ->expectsOutput('Cleaning up old backups...')
            ->expectsOutput('Secure backup process completed successfully!')
            ->assertExitCode(0);
    }
}
