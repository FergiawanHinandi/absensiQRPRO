<?php

namespace App\Console\Commands\Chaos;

use App\Services\FailSecure\FailSecureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Chaos Engineering Command: Simulate Disk Full
 *
 * This command simulates disk space exhaustion to test
 * fail-secure mechanisms under storage pressure.
 *
 * WARNING: For staging/testing environments only!
 */
class ChaosDiskFullCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chaos:disk-full
                            {--duration=60 : Duration in seconds to simulate disk full}
                            {--size=100 : Size in MB to fill disk}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate disk space exhaustion to test fail-secure mechanisms';

    /**
     * Execute the console command.
     */
    public function handle(FailSecureService $failSecureService): int
    {
        // Safety check: Only allow in non-production environments
        if (app()->environment('production')) {
            $this->error('❌ Chaos testing is DISABLED in production environment!');
            $this->error('   This command can only be run in staging, testing, or local environments.');

            return Command::FAILURE;
        }

        $duration = (int) $this->option('duration');
        $sizeMB = (int) $this->option('size');
        $dryRun = $this->option('dry-run');

        $this->newLine();
        $this->warn('💾 CHAOS ENGINEERING: Disk Full Simulation');
        $this->warn('   Environment: '.app()->environment());
        $this->warn('   Duration: '.$duration.' seconds');
        $this->warn('   Size to fill: '.$sizeMB.'MB');
        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->showExpectedBehavior($sizeMB);

            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm('⚠️  This will create temporary files to simulate disk full. Continue?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('💾 Starting disk full simulation...');

        // Record the chaos test event
        $failSecureService->recordFallbackEvent(
            'chaos_test_started',
            'storage',
            'Disk full simulation started',
            null,
            [
                'duration' => $duration,
                'size_mb' => $sizeMB,
                'initiated_by' => 'chaos:disk-full command',
            ],
            'warning'
        );

        $endTime = now()->addSeconds($duration);
        $tempFiles = [];

        try {
            // Store chaos test state
            DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'active',
                    'metadata' => json_encode([
                        'type' => 'disk_full',
                        'started_at' => now()->toIso8601String(),
                        'ends_at' => $endTime->toIso8601String(),
                        'duration' => $duration,
                        'size_mb' => $sizeMB,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Create temporary large files to simulate disk full
            $this->info('📝 Creating temporary files to fill disk...');
            $chunkSizeMB = 10; // Create 10MB chunks
            $chunks = ceil($sizeMB / $chunkSizeMB);
            
            $progressBar = $this->output->createProgressBar($chunks);
            $progressBar->start();

            for ($i = 0; $i < $chunks; $i++) {
                $fileName = "chaos_test/disk_full_" . time() . "_" . $i . ".tmp";
                $content = str_repeat('X', $chunkSizeMB * 1024 * 1024); // 10MB of data
                
                try {
                    Storage::put($fileName, $content);
                    $tempFiles[] = $fileName;
                } catch (\Exception $e) {
                    $this->warn("\n   Could not create file: " . $e->getMessage());
                    break;
                }
                
                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->info('✅ Chaos test activated');
            $this->info('   - Files created: ' . count($tempFiles));
            $this->info('   - Approximate size: ' . (count($tempFiles) * $chunkSizeMB) . 'MB');
            $this->newLine();

            // Check disk space
            $diskSpace = $this->checkDiskSpace();
            if ($diskSpace['used_percent'] > 90) {
                $this->warn('   ⚠️  Disk usage: ' . $diskSpace['used_percent'] . '% (CRITICAL)');
                
                // Force system into degraded mode
                $failSecureService->forceDegradedMode(true);
                $failSecureService->updateComponentState(
                    FailSecureService::COMPONENT_STORAGE,
                    FailSecureService::STATE_DEGRADED,
                    null,
                    'Chaos test: Simulated disk full'
                );
            } else {
                $this->info('   Disk usage: ' . $diskSpace['used_percent'] . '%');
            }

            // Show progress bar for duration
            $this->info('⏳ Simulation running...');
            $progressBar = $this->output->createProgressBar($duration);
            $progressBar->start();

            $testResults = [
                'files_created' => count($tempFiles),
                'size_mb' => count($tempFiles) * $chunkSizeMB,
                'disk_usage_percent' => $diskSpace['used_percent'],
            ];

            for ($i = 0; $i < $duration; $i++) {
                sleep(1);
                $progressBar->advance();

                // Periodically log status
                if ($i > 0 && $i % 10 === 0) {
                    $this->logChaosStatus($failSecureService, $duration - $i);
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            // End chaos test
            $this->info('🔄 Ending chaos simulation...');

            // Cleanup temporary files
            $this->info('🧹 Cleaning up temporary files...');
            $cleanupBar = $this->output->createProgressBar(count($tempFiles));
            $cleanupBar->start();

            foreach ($tempFiles as $file) {
                try {
                    Storage::delete($file);
                } catch (\Exception $e) {
                    $this->warn("\n   Could not delete file: " . $e->getMessage());
                }
                $cleanupBar->advance();
            }

            $cleanupBar->finish();
            $this->newLine(2);

            // Clear degraded mode
            $failSecureService->forceDegradedMode(false);
            $failSecureService->clearStateCache();

            // Reset storage component state
            $failSecureService->updateComponentState(
                FailSecureService::COMPONENT_STORAGE,
                FailSecureService::STATE_HEALTHY,
                null,
                null
            );

            // Update chaos test state
            DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'inactive',
                    'metadata' => json_encode([
                        'type' => 'disk_full',
                        'completed_at' => now()->toIso8601String(),
                        'duration' => $duration,
                        'results' => $testResults,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Record completion
            $failSecureService->recordFallbackEvent(
                'chaos_test_completed',
                'storage',
                'Disk full simulation completed successfully',
                null,
                [
                    'duration' => $duration,
                    'results' => $testResults,
                ],
                'info'
            );

            $this->newLine();
            $this->info('✅ Chaos test completed successfully!');
            $this->showTestResults($failSecureService, $duration, $testResults);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('❌ Chaos test failed: '.$e->getMessage());

            // Cleanup
            try {
                $this->info('🧹 Cleaning up temporary files...');
                foreach ($tempFiles as $file) {
                    try {
                        Storage::delete($file);
                    } catch (\Exception $cleanupError) {
                        // Ignore cleanup errors
                    }
                }
                $failSecureService->forceDegradedMode(false);
                $failSecureService->clearStateCache();
            } catch (\Throwable $cleanupError) {
                $this->warn('   Could not fully restore state: '.$cleanupError->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Check current disk space
     */
    protected function checkDiskSpace(): array
    {
        $path = storage_path();
        $totalSpace = disk_total_space($path);
        $freeSpace = disk_free_space($path);
        $usedSpace = $totalSpace - $freeSpace;
        $usedPercent = round(($usedSpace / $totalSpace) * 100, 2);

        return [
            'total_gb' => round($totalSpace / (1024 ** 3), 2),
            'free_gb' => round($freeSpace / (1024 ** 3), 2),
            'used_gb' => round($usedSpace / (1024 ** 3), 2),
            'used_percent' => $usedPercent,
        ];
    }

    /**
     * Show expected behavior during chaos test
     */
    protected function showExpectedBehavior(int $sizeMB): void
    {
        $this->info('📋 Expected Behavior During Disk Full Simulation:');
        $this->newLine();

        $this->line('   <fg=yellow>1. Storage Operations</fg=yellow>');
        $this->line("      - Will create {$sizeMB}MB of temporary files");
        $this->line('      - File operations may start failing');
        $this->line('      - Export operations may be blocked');
        $this->newLine();

        $this->line('   <fg=yellow>2. System Behavior</fg=yellow>');
        $this->line('      - Graceful error handling for write operations');
        $this->line('      - User-friendly error messages');
        $this->line('      - Automatic cleanup of old files (if configured)');
        $this->line('      - Admin alerts triggered');
        $this->newLine();

        $this->line('   <fg=yellow>3. Core Functionality</fg=yellow>');
        $this->line('      - Attendance scanning: Should continue (DB only)');
        $this->line('      - Report exports: May fail gracefully');
        $this->line('      - File uploads: Will be blocked');
        $this->line('      - Logs: May switch to database logging');
        $this->newLine();

        $this->line('   <fg=yellow>4. Recovery</fg=yellow>');
        $this->line('      - Temporary files will be cleaned up');
        $this->line('      - System should return to normal');
        $this->line('      - No data loss expected');
        $this->newLine();
    }

    /**
     * Log chaos status periodically
     */
    protected function logChaosStatus(FailSecureService $failSecureService, int $remainingSeconds): void
    {
        $diskSpace = $this->checkDiskSpace();

        \Log::info('Chaos Test: Disk full simulation in progress', [
            'remaining_seconds' => $remainingSeconds,
            'disk_usage_percent' => $diskSpace['used_percent'],
            'disk_free_gb' => $diskSpace['free_gb'],
            'system_state' => $failSecureService->getSystemState(),
            'is_degraded' => $failSecureService->isDegraded(),
        ]);
    }

    /**
     * Show test results after chaos test
     */
    protected function showTestResults(
        FailSecureService $failSecureService,
        int $duration,
        array $testResults
    ): void {
        $this->newLine();
        $this->info('📊 Test Results:');
        $this->newLine();

        $this->line('   Configuration:');
        $this->line("      - Duration: <fg=cyan>{$duration}s</fg=cyan>");
        $this->line("      - Files created: <fg=cyan>{$testResults['files_created']}</fg=cyan>");
        $this->line("      - Size filled: <fg=cyan>{$testResults['size_mb']}MB</fg=cyan>");
        $this->newLine();

        $this->line('   Disk Usage:');
        $diskSpace = $this->checkDiskSpace();
        $this->line("      - Current usage: <fg=cyan>{$diskSpace['used_percent']}%</fg=cyan>");
        $this->line("      - Free space: <fg=cyan>{$diskSpace['free_gb']}GB</fg=cyan>");
        $this->line("      - Total space: <fg=cyan>{$diskSpace['total_gb']}GB</fg=cyan>");
        $this->newLine();

        // Count fallback events during the test period
        try {
            $fallbackEvents = DB::table('fallback_events')
                ->where('created_at', '>=', now()->subSeconds($duration + 10))
                ->count();

            $this->line("   Fallback events recorded: <fg=cyan>{$fallbackEvents}</fg=cyan>");
        } catch (\Throwable $e) {
            $this->line('   Fallback events: <fg=red>Could not retrieve</fg=red>');
        }

        $this->newLine();
        $this->line('   Current State:');
        $this->line('      - System state: <fg=green>'.$failSecureService->getSystemState().'</fg=green>');
        $this->line('      - Degraded mode: '.($failSecureService->isDegraded() ? '<fg=yellow>Yes</fg=yellow>' : '<fg=green>No</fg=green>'));

        $this->newLine();
        $this->info('💡 Review the fallback_events table and storage logs for detailed analysis.');
    }
}
