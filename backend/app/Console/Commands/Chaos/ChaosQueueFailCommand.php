<?php

namespace App\Console\Commands\Chaos;

use App\Services\FailSecure\FailSecureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Chaos Engineering Command: Simulate Queue Failure
 *
 * This command simulates queue worker failures and backlog
 * to test fail-secure mechanisms under queue stress.
 *
 * WARNING: For staging/testing environments only!
 */
class ChaosQueueFailCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chaos:queue-fail
                            {--duration=60 : Duration in seconds to simulate queue failure}
                            {--backlog=2000 : Number of fake jobs to add to simulate backlog}
                            {--fail-rate=100 : Percentage of jobs that should fail (0-100)}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate queue failures and backlog to test fail-secure mechanisms';

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
        $backlogSize = (int) $this->option('backlog');
        $failRate = min(100, max(0, (int) $this->option('fail-rate')));
        $dryRun = $this->option('dry-run');

        $this->newLine();
        $this->warn('💥 CHAOS ENGINEERING: Queue Failure Simulation');
        $this->warn('   Environment: ' . app()->environment());
        $this->warn('   Duration: ' . $duration . ' seconds');
        $this->warn('   Backlog size: ' . $backlogSize . ' jobs');
        $this->warn('   Fail rate: ' . $failRate . '%');
        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->showExpectedBehavior($backlogSize, $failRate);
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm('⚠️  This will simulate queue failures and create backlog. Continue?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('📮 Starting queue failure simulation...');

        // Record the chaos test event
        $failSecureService->recordFallbackEvent(
            'chaos_test_started',
            'queue',
            'Queue failure simulation started',
            null,
            [
                'duration' => $duration,
                'backlog_size' => $backlogSize,
                'fail_rate' => $failRate,
                'initiated_by' => 'chaos:queue-fail command',
            ],
            'warning'
        );

        $endTime = now()->addSeconds($duration);

        try {
            // Store chaos test state
            DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'active',
                    'metadata' => json_encode([
                        'type' => 'queue_fail',
                        'started_at' => now()->toIso8601String(),
                        'ends_at' => $endTime->toIso8601String(),
                        'duration' => $duration,
                        'backlog_size' => $backlogSize,
                        'fail_rate' => $failRate,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Write chaos config file for middleware/services to pick up
            $chaosConfig = [
                'active' => true,
                'type' => 'queue_fail',
                'fail_rate' => $failRate,
                'ends_at' => $endTime->timestamp,
            ];
            $chaosFilePath = storage_path('framework/chaos_queue_fail.json');
            file_put_contents($chaosFilePath, json_encode($chaosConfig));

            $this->info('✅ Chaos test configuration set');

            // Simulate backlog by creating fake pending jobs
            $this->info('📥 Creating simulated job backlog...');
            $jobsCreated = $this->createSimulatedBacklog($backlogSize);
            $this->info("   Created {$jobsCreated} simulated pending jobs");

            // Check if backlog exceeds threshold and trigger degraded mode
            if ($backlogSize >= FailSecureService::QUEUE_BACKLOG_THRESHOLD) {
                $failSecureService->forceDegradedMode(true);
                $failSecureService->updateComponentState(
                    FailSecureService::COMPONENT_QUEUE,
                    FailSecureService::STATE_DEGRADED,
                    null,
                    'Chaos test: Simulated queue backlog'
                );
                $this->warn('   System entered DEGRADED mode due to queue backlog');
            }

            // Simulate failed jobs
            if ($failRate > 0) {
                $this->info('💀 Simulating failed jobs...');
                $failedJobs = $this->createSimulatedFailedJobs((int) ($backlogSize * ($failRate / 100)));
                $this->info("   Created {$failedJobs} simulated failed jobs");
            }

            $this->newLine();
            $this->info('   - System state: ' . $failSecureService->getSystemState());
            $this->info('   - Queue backlog threshold: ' . FailSecureService::QUEUE_BACKLOG_THRESHOLD);
            $this->newLine();

            // Show progress bar
            $this->info('⏳ Simulation running...');
            $progressBar = $this->output->createProgressBar($duration);
            $progressBar->start();

            $testResults = [
                'jobs_created' => $jobsCreated,
                'failed_jobs_created' => $failedJobs ?? 0,
                'degraded_mode_activated' => $backlogSize >= FailSecureService::QUEUE_BACKLOG_THRESHOLD,
            ];

            for ($i = 0; $i < $duration; $i++) {
                sleep(1);
                $progressBar->advance();

                // Periodically log status
                if ($i > 0 && $i % 10 === 0) {
                    $this->logChaosStatus($failSecureService, $duration - $i, $backlogSize);
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            // End chaos test
            $this->info('🔄 Ending chaos simulation...');

            // Cleanup simulated jobs
            $this->info('🧹 Cleaning up simulated jobs...');
            $this->cleanupSimulatedJobs();

            // Remove chaos config file
            if (file_exists($chaosFilePath)) {
                unlink($chaosFilePath);
            }

            // Clear degraded mode
            $failSecureService->forceDegradedMode(false);
            $failSecureService->clearStateCache();

            // Reset queue component state
            $failSecureService->updateComponentState(
                FailSecureService::COMPONENT_QUEUE,
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
                        'type' => 'queue_fail',
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
                'queue',
                'Queue failure simulation completed successfully',
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
            $this->error('❌ Chaos test failed: ' . $e->getMessage());

            // Cleanup
            try {
                $this->cleanupSimulatedJobs();
                $chaosFilePath = storage_path('framework/chaos_queue_fail.json');
                if (file_exists($chaosFilePath)) {
                    unlink($chaosFilePath);
                }
                $failSecureService->forceDegradedMode(false);
                $failSecureService->clearStateCache();
            } catch (\Throwable $cleanupError) {
                $this->warn('   Could not fully restore state: ' . $cleanupError->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Create simulated job backlog
     */
    protected function createSimulatedBacklog(int $count): int
    {
        $created = 0;

        // Check if jobs table exists
        try {
            if (!DB::getSchemaBuilder()->hasTable('jobs')) {
                $this->warn('   Jobs table does not exist, creating simulated records in memory only');
                return 0;
            }

            // Insert simulated jobs in batches
            $batchSize = 100;
            $batches = ceil($count / $batchSize);

            for ($batch = 0; $batch < $batches; $batch++) {
                $jobs = [];
                $batchCount = min($batchSize, $count - ($batch * $batchSize));

                for ($i = 0; $i < $batchCount; $i++) {
                    $jobs[] = [
                        'queue' => 'chaos_test',
                        'payload' => json_encode([
                            'displayName' => 'ChaosTestJob',
                            'job' => 'App\\Jobs\\ChaosTestJob',
                            'maxTries' => 1,
                            'data' => ['chaos_test' => true, 'created_at' => now()->toIso8601String()],
                        ]),
                        'attempts' => 0,
                        'reserved_at' => null,
                        'available_at' => now()->timestamp,
                        'created_at' => now()->timestamp,
                    ];
                }

                DB::table('jobs')->insert($jobs);
                $created += count($jobs);
            }
        } catch (\Throwable $e) {
            $this->warn('   Could not create simulated jobs: ' . $e->getMessage());
        }

        return $created;
    }

    /**
     * Create simulated failed jobs
     */
    protected function createSimulatedFailedJobs(int $count): int
    {
        $created = 0;

        try {
            if (!DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->warn('   Failed jobs table does not exist');
                return 0;
            }

            $batchSize = 100;
            $batches = ceil($count / $batchSize);

            for ($batch = 0; $batch < $batches; $batch++) {
                $jobs = [];
                $batchCount = min($batchSize, $count - ($batch * $batchSize));

                for ($i = 0; $i < $batchCount; $i++) {
                    $jobs[] = [
                        'uuid' => \Illuminate\Support\Str::uuid()->toString(),
                        'connection' => 'database',
                        'queue' => 'chaos_test',
                        'payload' => json_encode([
                            'displayName' => 'ChaosTestJob',
                            'job' => 'App\\Jobs\\ChaosTestJob',
                            'data' => ['chaos_test' => true],
                        ]),
                        'exception' => 'ChaosTest: Simulated job failure for testing fail-secure mechanisms',
                        'failed_at' => now(),
                    ];
                }

                DB::table('failed_jobs')->insert($jobs);
                $created += count($jobs);
            }
        } catch (\Throwable $e) {
            $this->warn('   Could not create simulated failed jobs: ' . $e->getMessage());
        }

        return $created;
    }

    /**
     * Cleanup simulated jobs
     */
    protected function cleanupSimulatedJobs(): void
    {
        try {
            // Delete simulated pending jobs
            $deletedPending = DB::table('jobs')
                ->where('queue', 'chaos_test')
                ->delete();
            $this->info("   Removed {$deletedPending} simulated pending jobs");

            // Delete simulated failed jobs
            $deletedFailed = DB::table('failed_jobs')
                ->where('queue', 'chaos_test')
                ->delete();
            $this->info("   Removed {$deletedFailed} simulated failed jobs");

        } catch (\Throwable $e) {
            $this->warn('   Cleanup error: ' . $e->getMessage());
        }
    }

    /**
     * Show expected behavior during chaos test
     */
    protected function showExpectedBehavior(int $backlogSize, int $failRate): void
    {
        $this->info('📋 Expected Behavior During Queue Failure Simulation:');
        $this->newLine();

        $willTriggerDegraded = $backlogSize >= FailSecureService::QUEUE_BACKLOG_THRESHOLD;

        $this->line('   <fg=yellow>1. Queue Backlog</fg=yellow>');
        $this->line("      - Simulated backlog: {$backlogSize} jobs");
        $this->line('      - Threshold for degraded: ' . FailSecureService::QUEUE_BACKLOG_THRESHOLD . ' jobs');
        $this->line('      - Will trigger degraded mode: ' . ($willTriggerDegraded ? '<fg=red>YES</fg=red>' : '<fg=green>NO</fg=green>'));
        $this->newLine();

        $this->line('   <fg=yellow>2. Failed Jobs</fg=yellow>');
        $this->line("      - Simulated fail rate: {$failRate}%");
        $this->line("      - Expected failed jobs: " . (int) ($backlogSize * ($failRate / 100)));
        $this->newLine();

        if ($willTriggerDegraded) {
            $this->line('   <fg=yellow>3. System Behavior (DEGRADED MODE)</fg=yellow>');
            $this->line('      - Rate limits: REDUCED by 50%');
            $this->line('      - Non-essential jobs: May be delayed');
            $this->line('      - Admin alerts: Will be triggered');
            $this->line('      - Async operations: May switch to sync');
            $this->newLine();
        }

        $this->line('   <fg=yellow>4. Impact on Attendance System</fg=yellow>');
        $this->line('      - Security alerts: May be delayed');
        $this->line('      - Report exports: May be throttled');
        $this->line('      - Notifications: May be queued');
        $this->line('      - Core attendance: Still functional (sync)');
        $this->newLine();

        $this->line('   <fg=yellow>5. Monitoring</fg=yellow>');
        $this->line('      - Queue health status: Updated in health API');
        $this->line('      - Fallback events: Recorded in database');
        $this->line('      - Admin dashboard: Shows queue status');
        $this->newLine();
    }

    /**
     * Log chaos status periodically
     */
    protected function logChaosStatus(FailSecureService $failSecureService, int $remainingSeconds, int $backlogSize): void
    {
        // Get current queue status
        $currentBacklog = 0;
        try {
            $currentBacklog = DB::table('jobs')->count();
        } catch (\Throwable $e) {
            // Ignore
        }

        \Log::info('Chaos Test: Queue failure simulation in progress', [
            'remaining_seconds' => $remainingSeconds,
            'initial_backlog' => $backlogSize,
            'current_backlog' => $currentBacklog,
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

        $this->line('   Simulation:');
        $this->line("      - Duration: <fg=cyan>{$duration}s</fg=cyan>");
        $this->line("      - Jobs created: <fg=cyan>{$testResults['jobs_created']}</fg=cyan>");
        $this->line("      - Failed jobs created: <fg=cyan>{$testResults['failed_jobs_created']}</fg=cyan>");
        $this->line('      - Degraded mode activated: ' .
            ($testResults['degraded_mode_activated'] ? '<fg=yellow>Yes</fg=yellow>' : '<fg=green>No</fg=green>'));
        $this->newLine();

        // Count fallback events during the test period
        try {
            $fallbackEvents = DB::table('fallback_events')
                ->where('created_at', '>=', now()->subSeconds($duration + 10))
                ->count();

            $this->line("   Fallback events recorded: <fg=cyan>{$fallbackEvents}</fg=cyan>");
        } catch (\Throwable $e) {
            $this->line("   Fallback events: <fg=red>Could not retrieve</fg=red>");
        }

        $this->newLine();
        $this->line('   Current State:');
        $this->line('      - System state: <fg=green>' . $failSecureService->getSystemState() . '</fg=green>');
        $this->line('      - Degraded mode: ' . ($failSecureService->isDegraded() ? '<fg=yellow>Yes</fg=yellow>' : '<fg=green>No</fg=green>'));

        // Current queue status
        try {
            $currentJobs = DB::table('jobs')->count();
            $currentFailed = DB::table('failed_jobs')->count();
            $this->line("      - Current pending jobs: <fg=cyan>{$currentJobs}</fg=cyan>");
            $this->line("      - Current failed jobs: <fg=cyan>{$currentFailed}</fg=cyan>");
        } catch (\Throwable $e) {
            // Ignore
        }

        $this->newLine();
        $this->info('💡 Review the fallback_events table and queue status for detailed analysis.');
        $this->info('   Run `php artisan queue:failed` to see failed jobs.');
    }
}
