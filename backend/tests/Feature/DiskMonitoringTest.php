<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiskMonitoringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear cache before each test
        Cache::flush();

        // Create test directories
        Storage::makeDirectory('exports');
        Storage::makeDirectory('temp');
    }

    protected function tearDown(): void
    {
        // Clean up test files
        Storage::deleteDirectory('exports');
        Storage::deleteDirectory('temp');

        parent::tearDown();
    }

    /**
     * **Validates: Requirements Week 4 Day 20.1**
     * 
     * Property 59: Disk space checked hourly
     * 
*/
    public function it_monitors_disk_space_successfully()
    {
        // Arrange: Ensure disk monitoring is enabled
        config(['monitoring.enabled' => true]);

        // Act: Run the disk monitor command
        $exitCode = Artisan::call('disk:monitor');

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Disk space monitoring completed', $output);
        $this->assertStringContainsString('Disk Space:', $output);
    }

    /**
     * **Validates: Requirements Week 4 Day 20.2**
     * 
     * Property 60: Alert triggered if disk > 90% full
     * 
*/
    public function it_alerts_when_disk_space_exceeds_threshold()
    {
        // Arrange: Set very low threshold to trigger alert
        config(['monitoring.alerts.enabled' => true]);

        // Expect log warning
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')
            ->with('Disk space alert triggered', \Mockery::type('array'))
            ->once();

        // Act: Run the monitor command with low threshold
        $exitCode = Artisan::call('disk:monitor', ['--disk-threshold' => 0]);

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('⚠️', $output);
    }

    /**
     * **Validates: Requirements Week 4 Day 20.3**
     * 
     * Property 61: Old exports cleaned automatically
     * 
*/
    public function it_cleans_up_old_export_files()
    {
        // Arrange: Create old export files
        $oldFile = storage_path('app/exports/old_export.xlsx');
        $newFile = storage_path('app/exports/new_export.xlsx');

        // Create files
        file_put_contents($oldFile, 'old content');
        file_put_contents($newFile, 'new content');

        // Set old file timestamp (8 days ago)
        touch($oldFile, now()->subDays(8)->timestamp);
        touch($newFile, now()->timestamp);

        // Act: Run the monitor command with 7-day cleanup
        $exitCode = Artisan::call('disk:monitor', ['--cleanup-days' => 7]);

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Cleaned up', $output);
        
        // Old file should be deleted
        $this->assertFileDoesNotExist($oldFile);
        
        // New file should still exist
        $this->assertFileExists($newFile);

        // Cleanup
        @unlink($newFile);
    }


    #[Test]
    public function it_monitors_storage_directory_usage()
    {
        // Arrange: Create test files in storage directories
        Storage::put('exports/test1.xlsx', 'test content 1');
        Storage::put('temp/test2.tmp', 'test content 2');

        // Act: Run the disk monitor command
        $exitCode = Artisan::call('disk:monitor');

        // Assert
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Storage Directory Usage:', $output);
    }


    #[Test]
    public function it_cleans_up_old_temporary_files()
    {
        // Arrange: Create old temporary files
        $oldTempFile = storage_path('app/temp/old_temp.tmp');
        $newTempFile = storage_path('app/temp/new_temp.tmp');

        // Create files
        file_put_contents($oldTempFile, 'old temp content');
        file_put_contents($newTempFile, 'new temp content');

        // Set old file timestamp (8 days ago)
        touch($oldTempFile, now()->subDays(8)->timestamp);
        touch($newTempFile, now()->timestamp);

        // Act: Run the monitor command with 7-day cleanup
        $exitCode = Artisan::call('disk:monitor', ['--cleanup-days' => 7]);

        // Assert
        $this->assertEquals(0, $exitCode);
        
        // Old temp file should be deleted
        $this->assertFileDoesNotExist($oldTempFile);
        
        // New temp file should still exist
        $this->assertFileExists($newTempFile);

        // Cleanup
        @unlink($newTempFile);
    }


    #[Test]
    public function it_implements_alert_cooldown()
    {
        // Arrange: Set low threshold to trigger alert
        config(['monitoring.alerts.enabled' => true]);

        // First alert should be sent
        Artisan::call('disk:monitor', ['--disk-threshold' => 0]);

        // Check that cooldown was set
        $cacheKey = 'disk_space_alert';
        $this->assertTrue(Cache::has($cacheKey));

        $alertData = Cache::get($cacheKey);
        $this->assertArrayHasKey('sent_at', $alertData);

        // Second alert should be suppressed (within cooldown)
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->never(); // Should not be called again

        Artisan::call('disk:monitor', ['--disk-threshold' => 0]);
    }


    #[Test]
    public function it_suppresses_alerts_during_maintenance_mode()
    {
        // Arrange: Enable maintenance mode
        config(['monitoring.maintenance.suppress_alerts' => true]);
        Artisan::call('down');

        // Set low threshold to trigger alert
        config(['monitoring.alerts.enabled' => true]);

        // Expect log info about suppression
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('Alert suppressed during maintenance', \Mockery::type('array'))
            ->once();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->andReturnSelf();

        // Act: Run the monitor command
        Artisan::call('disk:monitor', ['--disk-threshold' => 0]);

        // Cleanup
        Artisan::call('up');
    }


    #[Test]
    public function it_respects_custom_cleanup_days_option()
    {
        // Arrange: Create files with different ages
        $file5Days = storage_path('app/exports/file_5_days.xlsx');
        $file10Days = storage_path('app/exports/file_10_days.xlsx');

        file_put_contents($file5Days, 'content 5 days');
        file_put_contents($file10Days, 'content 10 days');

        touch($file5Days, now()->subDays(5)->timestamp);
        touch($file10Days, now()->subDays(10)->timestamp);

        // Act: Run with 7-day cleanup (should only delete 10-day file)
        $exitCode = Artisan::call('disk:monitor', ['--cleanup-days' => 7]);

        // Assert
        $this->assertEquals(0, $exitCode);
        
        // 5-day file should still exist
        $this->assertFileExists($file5Days);
        
        // 10-day file should be deleted
        $this->assertFileDoesNotExist($file10Days);

        // Cleanup
        @unlink($file5Days);
    }


    #[Test]
    public function it_only_cleans_files_with_specified_extensions()
    {
        // Arrange: Create files with different extensions
        $xlsxFile = storage_path('app/exports/old_file.xlsx');
        $txtFile = storage_path('app/exports/old_file.txt');

        file_put_contents($xlsxFile, 'xlsx content');
        file_put_contents($txtFile, 'txt content');

        // Set both files to old timestamp
        touch($xlsxFile, now()->subDays(8)->timestamp);
        touch($txtFile, now()->subDays(8)->timestamp);

        // Act: Run cleanup (should only delete xlsx, pdf, csv files)
        $exitCode = Artisan::call('disk:monitor', ['--cleanup-days' => 7]);

        // Assert
        $this->assertEquals(0, $exitCode);
        
        // xlsx file should be deleted (in allowed extensions)
        $this->assertFileDoesNotExist($xlsxFile);
        
        // txt file should still exist (not in allowed extensions)
        $this->assertFileExists($txtFile);

        // Cleanup
        @unlink($txtFile);
    }


    #[Test]
    public function it_logs_disk_space_metrics()
    {
        // Arrange: Expect log info with metrics
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('Disk space monitored', \Mockery::on(function ($arg) {
                return isset($arg['total_bytes']) 
                    && isset($arg['used_bytes']) 
                    && isset($arg['free_bytes'])
                    && isset($arg['used_percent']);
            }))
            ->once();
        Log::shouldReceive('info')->andReturnSelf();

        // Act: Run the monitor command
        Artisan::call('disk:monitor');
    }


    #[Test]
    public function it_logs_cleanup_results()
    {
        // Arrange: Create old files
        $oldFile = storage_path('app/exports/old_export.xlsx');
        file_put_contents($oldFile, 'old content');
        touch($oldFile, now()->subDays(8)->timestamp);

        // Expect log info with cleanup results
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('Old files cleaned up', \Mockery::on(function ($arg) {
                return isset($arg['total_cleaned']) 
                    && isset($arg['exports_cleaned'])
                    && isset($arg['cutoff_date']);
            }))
            ->once();
        Log::shouldReceive('info')->andReturnSelf();

        // Act: Run the monitor command
        Artisan::call('disk:monitor', ['--cleanup-days' => 7]);
    }


    #[Test]
    public function it_handles_missing_directories_gracefully()
    {
        // Arrange: Delete test directories
        Storage::deleteDirectory('exports');
        Storage::deleteDirectory('temp');

        // Act: Run the monitor command (should not crash)
        $exitCode = Artisan::call('disk:monitor');

        // Assert: Command should complete successfully
        $this->assertEquals(0, $exitCode);
        $output = Artisan::output();
        $this->assertStringContainsString('Disk space monitoring completed', $output);
    }


    #[Test]
    public function it_handles_file_deletion_errors_gracefully()
    {
        // Arrange: Create a file in a protected directory (simulate permission error)
        $testFile = storage_path('app/exports/test_file.xlsx');
        file_put_contents($testFile, 'test content');
        touch($testFile, now()->subDays(8)->timestamp);

        // Make file read-only to simulate deletion error
        chmod($testFile, 0444);

        // Expect warning log for failed deletion
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('warning')
            ->with('Failed to delete old file', \Mockery::type('array'))
            ->atLeast()->once();
        Log::shouldReceive('info')->andReturnSelf();

        // Act: Run the monitor command
        $exitCode = Artisan::call('disk:monitor', ['--cleanup-days' => 7]);

        // Assert: Command should complete successfully despite error
        $this->assertEquals(0, $exitCode);

        // Cleanup: Restore permissions and delete
        chmod($testFile, 0644);
        @unlink($testFile);
    }


    #[Test]
    public function it_monitors_storage_directory_usage_with_metrics()
    {
        // Arrange: Create test files
        Storage::put('exports/test1.xlsx', str_repeat('x', 1024 * 100)); // 100KB
        Storage::put('temp/test2.tmp', str_repeat('y', 1024 * 50)); // 50KB

        // Expect log info for each directory
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')
            ->with('Storage directory monitored', \Mockery::on(function ($arg) {
                return isset($arg['directory']) 
                    && isset($arg['size_bytes'])
                    && isset($arg['size_mb']);
            }))
            ->atLeast()->once();
        Log::shouldReceive('info')->andReturnSelf();

        // Act: Run the monitor command
        Artisan::call('disk:monitor');
    }


    #[Test]
    public function it_respects_alerts_enabled_configuration()
    {
        // Arrange: Disable alerts
        config(['monitoring.alerts.enabled' => false]);

        // Set low threshold that would normally trigger alert
        Log::shouldReceive('channel')
            ->with('system')
            ->andReturnSelf();
        Log::shouldReceive('info')->andReturnSelf();
        Log::shouldReceive('warning')->never(); // Alert should not be sent

        // Act: Run the monitor command
        $exitCode = Artisan::call('disk:monitor', ['--disk-threshold' => 0]);

        // Assert: Command completes but no alert sent
        $this->assertEquals(0, $exitCode);
    }
}
