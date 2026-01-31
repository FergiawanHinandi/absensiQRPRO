<?php

namespace App\Console\Commands\Chaos;

use App\Services\FailSecure\FailSecureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

/**
 * Chaos Engineering Command: Simulate Redis Down
 *
 * This command simulates Redis being unavailable to test
 * fail-secure fallback mechanisms.
 *
 * WARNING: For staging/testing environments only!
 */
class ChaosRedisDownCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chaos:redis-down
                            {--duration=60 : Duration in seconds to simulate Redis down}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate Redis being unavailable to test fail-secure mechanisms';

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
        $dryRun = $this->option('dry-run');

        $this->newLine();
        $this->warn('🔥 CHAOS ENGINEERING: Redis Down Simulation');
        $this->warn('   Environment: ' . app()->environment());
        $this->warn('   Duration: ' . $duration . ' seconds');
        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->showExpectedBehavior();
            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (!$this->confirm('⚠️  This will simulate Redis being unavailable. Continue?')) {
            $this->info('Cancelled.');
            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('📡 Starting Redis down simulation...');

        // Record the chaos test event
        $failSecureService->recordFallbackEvent(
            'chaos_test_started',
            'redis',
            'Redis down simulation started',
            null,
            ['duration' => $duration, 'initiated_by' => 'chaos:redis-down command'],
            'warning'
        );

        // Set a flag that the system is in chaos test mode
        $chaosKey = 'chaos:redis_down_simulation';
        $endTime = now()->addSeconds($duration);

        try {
            // Store chaos test state in database (since Redis might be "down")
            \DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'active',
                    'metadata' => json_encode([
                        'type' => 'redis_down',
                        'started_at' => now()->toIso8601String(),
                        'ends_at' => $endTime->toIso8601String(),
                        'duration' => $duration,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Force system into degraded mode
            $failSecureService->forceDegradedMode(true);

            $this->info('✅ Chaos test activated');
            $this->info('   - System state: DEGRADED');
            $this->info('   - Rate limit fallback: ACTIVE (using DB)');
            $this->info('   - Policy fallback: ACTIVE (using safe defaults)');
            $this->newLine();

            // Show progress bar
            $this->info('⏳ Simulation running...');
            $progressBar = $this->output->createProgressBar($duration);
            $progressBar->start();

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

            // Clear degraded mode
            $failSecureService->forceDegradedMode(false);
            $failSecureService->clearStateCache();

            // Update chaos test state
            \DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'inactive',
                    'metadata' => json_encode([
                        'type' => 'redis_down',
                        'completed_at' => now()->toIso8601String(),
                        'duration' => $duration,
                    ]),
                    'updated_at' => now(),
                ]
            );

            // Record completion
            $failSecureService->recordFallbackEvent(
                'chaos_test_completed',
                'redis',
                'Redis down simulation completed successfully',
                null,
                ['duration' => $duration],
                'info'
            );

            $this->newLine();
            $this->info('✅ Chaos test completed successfully!');
            $this->showTestResults($failSecureService, $duration);

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('❌ Chaos test failed: ' . $e->getMessage());

            // Try to restore normal state
            try {
                $failSecureService->forceDegradedMode(false);
                $failSecureService->clearStateCache();
            } catch (\Throwable $cleanupError) {
                $this->warn('   Could not fully restore state: ' . $cleanupError->getMessage());
            }

            return Command::FAILURE;
        }
    }

    /**
     * Show expected behavior during chaos test
     */
    protected function showExpectedBehavior(): void
    {
        $this->info('📋 Expected Behavior During Redis Down Simulation:');
        $this->newLine();

        $this->line('   <fg=yellow>1. Rate Limiting</fg=yellow>');
        $this->line('      - Redis rate limiter will fail');
        $this->line('      - System will fall back to database-based rate limiting');
        $this->line('      - Rate limits will be STRICTER (50% reduction)');
        $this->newLine();

        $this->line('   <fg=yellow>2. Policy Service</fg=yellow>');
        $this->line('      - Cache lookups will fail');
        $this->line('      - System will use SAFE DEFAULTS (stricter values)');
        $this->line('      - Geofence radius: 30m (normally 50m)');
        $this->line('      - QR expiry: 5 min (normally 10 min)');
        $this->newLine();

        $this->line('   <fg=yellow>3. System State</fg=yellow>');
        $this->line('      - System will enter DEGRADED mode');
        $this->line('      - Admin dashboard will show degraded status');
        $this->line('      - All fallback events will be logged');
        $this->newLine();

        $this->line('   <fg=yellow>4. Attendance Scans</fg=yellow>');
        $this->line('      - Will continue to work with stricter rules');
        $this->line('      - Location validation: REQUIRED');
        $this->line('      - Device validation: REQUIRED');
        $this->newLine();
    }

    /**
     * Log chaos status periodically
     */
    protected function logChaosStatus(FailSecureService $failSecureService, int $remainingSeconds): void
    {
        \Log::info('Chaos Test: Redis down simulation in progress', [
            'remaining_seconds' => $remainingSeconds,
            'system_state' => $failSecureService->getSystemState(),
            'is_degraded' => $failSecureService->isDegraded(),
        ]);
    }

    /**
     * Show test results after chaos test
     */
    protected function showTestResults(FailSecureService $failSecureService, int $duration): void
    {
        $this->newLine();
        $this->info('📊 Test Results:');
        $this->newLine();

        // Count fallback events during the test period
        try {
            $fallbackEvents = \DB::table('fallback_events')
                ->where('created_at', '>=', now()->subSeconds($duration + 10))
                ->count();

            $this->line("   Fallback events recorded: <fg=cyan>{$fallbackEvents}</fg=cyan>");
        } catch (\Throwable $e) {
            $this->line("   Fallback events: <fg=red>Could not retrieve</fg=red>");
        }

        // Current system state
        $this->line('   Current system state: <fg=green>' . $failSecureService->getSystemState() . '</fg=green>');
        $this->line('   Degraded mode: ' . ($failSecureService->isDegraded() ? '<fg=yellow>Yes</fg=yellow>' : '<fg=green>No</fg=green>'));

        $this->newLine();
        $this->info('💡 Review the fallback_events table and security logs for detailed analysis.');
    }
}
