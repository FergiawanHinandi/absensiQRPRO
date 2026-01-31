<?php

namespace App\Console\Commands\Chaos;

use App\Services\FailSecure\FailSecureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Chaos Engineering Command: Simulate Slow Database
 *
 * This command simulates database latency to test
 * fail-secure mechanisms under slow DB conditions.
 *
 * WARNING: For staging/testing environments only!
 */
class ChaosDbSlowCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chaos:db-slow
                            {--duration=60 : Duration in seconds to simulate slow DB}
                            {--latency=3000 : Simulated latency in milliseconds}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate slow database responses to test fail-secure mechanisms';

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
        $latency = (int) $this->option('latency');
        $dryRun = $this->option('dry-run');

        $this->newLine();
        $this->warn('🐌 CHAOS ENGINEERING: Slow Database Simulation');
        $this->warn('   Environment: ' . app()->environment());
        $this->warn('   Duration: ' . $duration . ' seconds');
        $this->warn('   Simulated latency: ' . $latency . 'ms');
        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->showExpectedBehavior($latency);
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm('⚠️  This will simulate slow database responses. Continue?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('🐢 Starting slow database simulation...');

        // Record the chaos test event
        $failSecureService->recordFallbackEvent(
            'chaos_test_started',
            'database',
            'Slow database simulation started',
            null,
            [
                'duration' => $duration,
                'latency_ms' => $latency,
                'initiated_by' => 'chaos:db-slow command',
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
                        'type' => 'db_slow',
                        'started_at' => now()->toIso8601String(),
                        'ends_at' => $endTime->toIso8601String(),
                        'duration' => $duration,
                        'latency_ms' => $latency,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Store the latency simulation flag in cache (for middleware to pick up)
            // We use file cache as fallback since this is a chaos test
            $chaosConfig = [
                'active' => true,
                'type' => 'db_slow',
                'latency_ms' => $latency,
                'ends_at' => $endTime->timestamp,
            ];

            // Write to a file that middleware can check
            $chaosFilePath = storage_path('framework/chaos_db_slow.json');
            file_put_contents($chaosFilePath, json_encode($chaosConfig));

            // Force system into degraded mode since DB is slow
            if ($latency >= FailSecureService::DB_SLOW_THRESHOLD_MS) {
                $failSecureService->forceDegradedMode(true);
                $failSecureService->updateComponentState(
                    FailSecureService::COMPONENT_DATABASE,
                    FailSecureService::STATE_DEGRADED,
                    $latency,
                    'Chaos test: Simulated slow database'
                );
            }

            $this->info('✅ Chaos test activated');
            $this->info('   - Simulated latency: ' . $latency . 'ms');
            $this->info('   - System state: ' . ($latency >= 2000 ? 'DEGRADED' : 'HEALTHY (latency below threshold)'));
            $this->newLine();

            // Show progress bar
            $this->info('⏳ Simulation running...');
            $progressBar = $this->output->createProgressBar($duration);
            $progressBar->start();

            $startTime = time();
            $testResults = [
                'requests_simulated' => 0,
                'fallback_triggers' => 0,
            ];

            for ($i = 0; $i < $duration; $i++) {
                sleep(1);
                $progressBar->advance();

                // Every 5 seconds, simulate a slow query and record metrics
                if ($i > 0 && $i % 5 === 0) {
                    $this->simulateSlowQuery($latency, $testResults);
                    $this->logChaosStatus($failSecureService, $duration - $i, $latency);
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            // End chaos test
            $this->info('🔄 Ending chaos simulation...');

            // Remove chaos config file
            if (file_exists($chaosFilePath)) {
                unlink($chaosFilePath);
            }

            // Clear degraded mode
            $failSecureService->forceDegradedMode(false);
            $failSecureService->clearStateCache();

            // Reset database component state
            $failSecureService->updateComponentState(
                FailSecureService::COMPONENT_DATABASE,
                FailSecureService::STATE_HEALTHY,
                50, // Normal response time
                null
            );

            // Update chaos test state
            DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'inactive',
                    'metadata' => json_encode([
                        'type' => 'db_slow',
                        'completed_at' => now()->toIso8601String(),
                        'duration' => $duration,
                        'latency_ms' => $latency,
                        'results' => $testResults,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Record completion
            $failSecureService->recordFallbackEvent(
                'chaos_test_completed',
                'database',
                'Slow database simulation completed successfully',
                null,
                [
                    'duration' => $duration,
                    'latency_ms' => $latency,
                    'results' => $testResults,
                ],
                'info'
            );

            $this->newLine();
            $this->info('✅ Chaos test completed successfully!');
            $this->showTestResults($failSecureService, $duration, $latency, $testResults);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('❌ Chaos test failed: ' . $e->getMessage());

            // Cleanup
            try {
                $chaosFilePath = storage_path('framework/chaos_db_slow.json');
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
     * Simulate a slow database query
     */
    protected function simulateSlowQuery(int $latencyMs, array &$results): void
    {
        $results['requests_simulated']++;

        // Actually introduce latency to test the system's response
        $sleepMicroseconds = $latencyMs * 1000;

        // Simulate a query with artificial delay
        $start = microtime(true);
        usleep($sleepMicroseconds);
        $elapsed = (microtime(true) - $start) * 1000;

        // Check if this would trigger fallback (>2000ms)
        if ($elapsed >= FailSecureService::DB_SLOW_THRESHOLD_MS) {
            $results['fallback_triggers']++;
        }
    }

    /**
     * Show expected behavior during chaos test
     */
    protected function showExpectedBehavior(int $latencyMs): void
    {
        $this->info('📋 Expected Behavior During Slow DB Simulation:');
        $this->newLine();

        $willTriggerDegraded = $latencyMs >= FailSecureService::DB_SLOW_THRESHOLD_MS;

        $this->line('   <fg=yellow>1. Database Response Time</fg=yellow>');
        $this->line("      - Simulated latency: {$latencyMs}ms");
        $this->line('      - Threshold for degraded: ' . FailSecureService::DB_SLOW_THRESHOLD_MS . 'ms');
        $this->line('      - Will trigger degraded mode: ' . ($willTriggerDegraded ? '<fg=red>YES</fg=red>' : '<fg=green>NO</fg=green>'));
        $this->newLine();

        if ($willTriggerDegraded) {
            $this->line('   <fg=yellow>2. System Behavior (DEGRADED MODE)</fg=yellow>');
            $this->line('      - Rate limits: REDUCED by 50%');
            $this->line('      - Policy lookups: May use SAFE DEFAULTS');
            $this->line('      - Non-essential features: May be disabled');
            $this->line('      - Admin alerts: Will be triggered');
            $this->newLine();

            $this->line('   <fg=yellow>3. Security Impact</fg=yellow>');
            $this->line('      - Geofence radius: 30m (stricter)');
            $this->line('      - QR expiry: 5 min (shorter)');
            $this->line('      - Login attempts: 3 (reduced)');
            $this->line('      - API rate limit: 30/min (reduced)');
            $this->newLine();
        } else {
            $this->line('   <fg=yellow>2. System Behavior (NORMAL MODE)</fg=yellow>');
            $this->line('      - Latency is below threshold');
            $this->line('      - System will continue normal operation');
            $this->line('      - Requests will be slower but not degraded');
            $this->newLine();
        }

        $this->line('   <fg=yellow>4. Monitoring</fg=yellow>');
        $this->line('      - All slow queries will be logged');
        $this->line('      - Fallback events will be recorded');
        $this->line('      - Health check API will reflect status');
        $this->newLine();
    }

    /**
     * Log chaos status periodically
     */
    protected function logChaosStatus(FailSecureService $failSecureService, int $remainingSeconds, int $latencyMs): void
    {
        \Log::info('Chaos Test: Slow DB simulation in progress', [
            'remaining_seconds' => $remainingSeconds,
            'simulated_latency_ms' => $latencyMs,
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
        int $latencyMs,
        array $testResults
    ): void {
        $this->newLine();
        $this->info('📊 Test Results:');
        $this->newLine();

        $this->line('   Configuration:');
        $this->line("      - Duration: <fg=cyan>{$duration}s</fg=cyan>");
        $this->line("      - Simulated latency: <fg=cyan>{$latencyMs}ms</fg=cyan>");
        $this->newLine();

        $this->line('   Metrics:');
        $this->line("      - Slow queries simulated: <fg=cyan>{$testResults['requests_simulated']}</fg=cyan>");
        $this->line("      - Fallback triggers: <fg=cyan>{$testResults['fallback_triggers']}</fg=cyan>");

        // Count fallback events during the test period
        try {
            $fallbackEvents = DB::table('fallback_events')
                ->where('created_at', '>=', now()->subSeconds($duration + 10))
                ->count();

            $this->line("      - Fallback events recorded: <fg=cyan>{$fallbackEvents}</fg=cyan>");
        } catch (\Throwable $e) {
            $this->line("      - Fallback events: <fg=red>Could not retrieve</fg=red>");
        }

        $this->newLine();
        $this->line('   Current State:');
        $this->line('      - System state: <fg=green>' . $failSecureService->getSystemState() . '</fg=green>');
        $this->line('      - Degraded mode: ' . ($failSecureService->isDegraded() ? '<fg=yellow>Yes</fg=yellow>' : '<fg=green>No</fg=green>'));

        $this->newLine();
        $this->info('💡 Review the fallback_events table and security logs for detailed analysis.');
    }
}
