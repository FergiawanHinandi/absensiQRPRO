<?php

namespace App\Console\Commands\Chaos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Chaos Engineering Test Runner
 *
 * This command orchestrates all chaos testing scenarios
 * and generates a comprehensive report.
 *
 * WARNING: For staging/testing environments only!
 */
class ChaosTestRunnerCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chaos:run-all
                            {--duration=60 : Duration for each test in seconds}
                            {--skip= : Comma-separated list of tests to skip (redis,db,queue,disk,load)}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Run all chaos testing scenarios and generate comprehensive report';

    protected array $testResults = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Safety check: Only allow in non-production environments
        if (app()->environment('production')) {
            $this->error('❌ Chaos testing is DISABLED in production environment!');
            $this->error('   This command can only be run in staging, testing, or local environments.');

            return Command::FAILURE;
        }

        $duration = (int) $this->option('duration');
        $skipTests = $this->option('skip') ? explode(',', $this->option('skip')) : [];
        $dryRun = $this->option('dry-run');

        $this->newLine();
        $this->warn('🔥 CHAOS ENGINEERING: Full Test Suite');
        $this->warn('   Environment: '.app()->environment());
        $this->warn('   Duration per test: '.$duration.' seconds');
        if (!empty($skipTests)) {
            $this->warn('   Skipping: '.implode(', ', $skipTests));
        }
        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->showTestPlan($skipTests);

            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm('⚠️  This will run all chaos tests. This may take several minutes. Continue?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('🚀 Starting chaos testing suite...');
        $this->newLine();

        $startTime = now();

        // Test 1: Redis Failure
        if (!in_array('redis', $skipTests)) {
            $this->runTest('Redis Failure', 'chaos:redis-down', ['--duration' => $duration]);
        }

        // Test 2: Database Slow Query
        if (!in_array('db', $skipTests)) {
            $this->runTest('Database Slow Query', 'chaos:db-slow', ['--duration' => $duration, '--latency' => 3000]);
        }

        // Test 3: Queue Failure
        if (!in_array('queue', $skipTests)) {
            $this->runTest('Queue Failure', 'chaos:queue-fail', ['--duration' => $duration, '--backlog' => 2000]);
        }

        // Test 4: Disk Full
        if (!in_array('disk', $skipTests)) {
            $this->runTest('Disk Full', 'chaos:disk-full', ['--duration' => $duration, '--size' => 100]);
        }

        // Test 5: High Load
        if (!in_array('load', $skipTests)) {
            $this->runTest('High Load', 'chaos:high-load', ['--duration' => $duration, '--requests' => 1000, '--concurrency' => 50]);
        }

        $endTime = now();
        $totalDuration = $endTime->diffInSeconds($startTime);

        $this->newLine(2);
        $this->info('✅ All chaos tests completed!');
        $this->newLine();

        // Generate comprehensive report
        $this->generateReport($totalDuration);

        // Save report to file
        $this->saveReportToFile($startTime, $endTime, $totalDuration);

        return Command::SUCCESS;
    }

    /**
     * Run a single chaos test
     */
    protected function runTest(string $name, string $command, array $options = []): void
    {
        $this->newLine();
        $this->info("═══════════════════════════════════════════════════════");
        $this->info("🧪 Test: {$name}");
        $this->info("═══════════════════════════════════════════════════════");
        $this->newLine();

        $startTime = microtime(true);

        try {
            $exitCode = Artisan::call($command, $options);
            $duration = round(microtime(true) - $startTime, 2);

            $this->testResults[$name] = [
                'status' => $exitCode === 0 ? 'PASSED' : 'FAILED',
                'duration' => $duration,
                'exit_code' => $exitCode,
                'command' => $command,
                'options' => $options,
            ];

            if ($exitCode === 0) {
                $this->info("✅ {$name}: PASSED ({$duration}s)");
            } else {
                $this->error("❌ {$name}: FAILED ({$duration}s)");
            }
        } catch (\Exception $e) {
            $duration = round(microtime(true) - $startTime, 2);

            $this->testResults[$name] = [
                'status' => 'ERROR',
                'duration' => $duration,
                'error' => $e->getMessage(),
                'command' => $command,
                'options' => $options,
            ];

            $this->error("❌ {$name}: ERROR - {$e->getMessage()}");
        }

        $this->newLine();
    }

    /**
     * Show test plan
     */
    protected function showTestPlan(array $skipTests): void
    {
        $this->info('📋 Chaos Testing Plan:');
        $this->newLine();

        $tests = [
            'redis' => '1. Redis Failure - Tests system behavior when Redis is unavailable',
            'db' => '2. Database Slow Query - Tests timeout handling and graceful degradation',
            'queue' => '3. Queue Failure - Tests queue backlog and failed job handling',
            'disk' => '4. Disk Full - Tests graceful error handling when disk is full',
            'load' => '5. High Load - Tests rate limiting and system stability under load',
        ];

        foreach ($tests as $key => $description) {
            if (in_array($key, $skipTests)) {
                $this->line("   <fg=gray>[SKIPPED] {$description}</fg=gray>");
            } else {
                $this->line("   <fg=yellow>[PLANNED] {$description}</fg=yellow>");
            }
        }

        $this->newLine();
    }

    /**
     * Generate comprehensive report
     */
    protected function generateReport(int $totalDuration): void
    {
        $this->info('═══════════════════════════════════════════════════════');
        $this->info('📊 CHAOS TESTING REPORT');
        $this->info('═══════════════════════════════════════════════════════');
        $this->newLine();

        // Summary table
        $tableData = [];
        $passedCount = 0;
        $failedCount = 0;

        foreach ($this->testResults as $name => $result) {
            $status = $result['status'];
            $statusColor = $status === 'PASSED' ? 'green' : 'red';
            
            $tableData[] = [
                $name,
                "<fg={$statusColor}>{$status}</fg={$statusColor}>",
                $result['duration'] . 's',
            ];

            if ($status === 'PASSED') {
                $passedCount++;
            } else {
                $failedCount++;
            }
        }

        $this->table(
            ['Test', 'Status', 'Duration'],
            $tableData
        );

        $this->newLine();

        // Overall statistics
        $totalTests = count($this->testResults);
        $successRate = $totalTests > 0 ? round(($passedCount / $totalTests) * 100, 2) : 0;

        $this->info('Overall Statistics:');
        $this->line("   Total tests: <fg=cyan>{$totalTests}</fg=cyan>");
        $this->line("   Passed: <fg=green>{$passedCount}</fg=green>");
        $this->line("   Failed: <fg=red>{$failedCount}</fg=red>");
        $this->line("   Success rate: <fg=cyan>{$successRate}%</fg=cyan>");
        $this->line("   Total duration: <fg=cyan>{$totalDuration}s</fg=cyan>");
        $this->newLine();

        // Fallback events analysis
        $this->analyzeFallbackEvents();

        // System health check
        $this->checkSystemHealth();

        // Recommendations
        $this->showRecommendations();
    }

    /**
     * Analyze fallback events
     */
    protected function analyzeFallbackEvents(): void
    {
        try {
            $recentEvents = DB::table('fallback_events')
                ->where('created_at', '>=', now()->subHour())
                ->get();

            $this->info('Fallback Events (Last Hour):');
            $this->line("   Total events: <fg=cyan>{$recentEvents->count()}</fg=cyan>");

            if ($recentEvents->count() > 0) {
                $byComponent = $recentEvents->groupBy('component');
                foreach ($byComponent as $component => $events) {
                    $this->line("   - {$component}: <fg=cyan>{$events->count()}</fg=cyan> events");
                }
            }

            $this->newLine();
        } catch (\Exception $e) {
            $this->warn('   Could not retrieve fallback events: ' . $e->getMessage());
            $this->newLine();
        }
    }

    /**
     * Check system health
     */
    protected function checkSystemHealth(): void
    {
        $this->info('System Health Check:');

        try {
            // Check database
            DB::connection()->getPdo();
            $this->line('   ✅ Database: Connected');
        } catch (\Exception $e) {
            $this->line('   ❌ Database: Disconnected');
        }

        try {
            // Check Redis
            \Cache::store('redis')->get('health_check');
            $this->line('   ✅ Redis: Connected');
        } catch (\Exception $e) {
            $this->line('   ⚠️  Redis: Disconnected (fallback active)');
        }

        try {
            // Check storage
            \Storage::put('health_check.tmp', 'test');
            \Storage::delete('health_check.tmp');
            $this->line('   ✅ Storage: Writable');
        } catch (\Exception $e) {
            $this->line('   ❌ Storage: Not writable');
        }

        try {
            // Check queue
            $queueSize = DB::table('jobs')->count();
            $this->line("   ✅ Queue: {$queueSize} pending jobs");
        } catch (\Exception $e) {
            $this->line('   ⚠️  Queue: Could not check');
        }

        $this->newLine();
    }

    /**
     * Show recommendations
     */
    protected function showRecommendations(): void
    {
        $this->info('💡 Recommendations:');
        $this->newLine();

        $failedTests = array_filter($this->testResults, fn($r) => $r['status'] !== 'PASSED');

        if (empty($failedTests)) {
            $this->line('   ✅ All tests passed! System resilience is excellent.');
            $this->line('   - Continue monitoring in production');
            $this->line('   - Run chaos tests regularly (monthly)');
            $this->line('   - Review fallback events for optimization opportunities');
        } else {
            $this->line('   ⚠️  Some tests failed. Review the following:');
            foreach ($failedTests as $name => $result) {
                $this->line("   - {$name}: Investigate and fix issues");
            }
            $this->line('   - Check logs for detailed error messages');
            $this->line('   - Review monitoring dashboards');
            $this->line('   - Consider adjusting thresholds or timeouts');
        }

        $this->newLine();
        $this->info('📚 Next Steps:');
        $this->line('   1. Review the detailed report saved to storage/logs/');
        $this->line('   2. Check fallback_events table for detailed analysis');
        $this->line('   3. Update runbook based on findings');
        $this->line('   4. Schedule regular chaos testing (monthly)');
        $this->newLine();
    }

    /**
     * Save report to file
     */
    protected function saveReportToFile($startTime, $endTime, $totalDuration): void
    {
        $reportPath = storage_path('logs/chaos_test_report_' . now()->format('Y-m-d_His') . '.txt');

        $report = "═══════════════════════════════════════════════════════\n";
        $report .= "CHAOS ENGINEERING TEST REPORT\n";
        $report .= "═══════════════════════════════════════════════════════\n\n";
        $report .= "Environment: " . app()->environment() . "\n";
        $report .= "Start Time: " . $startTime->toDateTimeString() . "\n";
        $report .= "End Time: " . $endTime->toDateTimeString() . "\n";
        $report .= "Total Duration: {$totalDuration}s\n\n";

        $report .= "TEST RESULTS:\n";
        $report .= "─────────────────────────────────────────────────────\n";
        foreach ($this->testResults as $name => $result) {
            $report .= "{$name}: {$result['status']} ({$result['duration']}s)\n";
            if (isset($result['error'])) {
                $report .= "  Error: {$result['error']}\n";
            }
        }

        $report .= "\n";
        $report .= "SUMMARY:\n";
        $report .= "─────────────────────────────────────────────────────\n";
        $totalTests = count($this->testResults);
        $passedCount = count(array_filter($this->testResults, fn($r) => $r['status'] === 'PASSED'));
        $failedCount = $totalTests - $passedCount;
        $successRate = $totalTests > 0 ? round(($passedCount / $totalTests) * 100, 2) : 0;

        $report .= "Total Tests: {$totalTests}\n";
        $report .= "Passed: {$passedCount}\n";
        $report .= "Failed: {$failedCount}\n";
        $report .= "Success Rate: {$successRate}%\n";

        file_put_contents($reportPath, $report);

        $this->info("📄 Report saved to: {$reportPath}");
        $this->newLine();
    }
}
