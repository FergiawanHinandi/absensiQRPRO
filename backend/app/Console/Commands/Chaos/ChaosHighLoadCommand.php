<?php

namespace App\Console\Commands\Chaos;

use App\Services\FailSecure\FailSecureService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Chaos Engineering Command: Simulate High Load
 *
 * This command simulates high concurrent load to test
 * rate limiting and system stability under stress.
 *
 * WARNING: For staging/testing environments only!
 */
class ChaosHighLoadCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'chaos:high-load
                            {--duration=60 : Duration in seconds to simulate high load}
                            {--requests=1000 : Total number of requests to send}
                            {--concurrency=50 : Number of concurrent requests}
                            {--url=http://localhost:8000 : Base URL to test}
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Simulate high concurrent load to test rate limiting and system stability';

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
        $totalRequests = (int) $this->option('requests');
        $concurrency = (int) $this->option('concurrency');
        $baseUrl = $this->option('url');
        $dryRun = $this->option('dry-run');

        $this->newLine();
        $this->warn('🔥 CHAOS ENGINEERING: High Load Simulation');
        $this->warn('   Environment: '.app()->environment());
        $this->warn('   Duration: '.$duration.' seconds');
        $this->warn('   Total requests: '.$totalRequests);
        $this->warn('   Concurrency: '.$concurrency);
        $this->warn('   Target URL: '.$baseUrl);
        $this->newLine();

        if ($dryRun) {
            $this->info('🔍 DRY RUN MODE - No changes will be made');
            $this->newLine();
            $this->showExpectedBehavior($totalRequests, $concurrency);

            return Command::SUCCESS;
        }

        // Confirm before proceeding
        if (! $this->confirm('⚠️  This will send '.$totalRequests.' requests to the system. Continue?')) {
            $this->info('Cancelled.');

            return Command::SUCCESS;
        }

        $this->newLine();
        $this->info('🚀 Starting high load simulation...');

        // Record the chaos test event
        $failSecureService->recordFallbackEvent(
            'chaos_test_started',
            'system',
            'High load simulation started',
            null,
            [
                'duration' => $duration,
                'total_requests' => $totalRequests,
                'concurrency' => $concurrency,
                'target_url' => $baseUrl,
                'initiated_by' => 'chaos:high-load command',
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
                        'type' => 'high_load',
                        'started_at' => now()->toIso8601String(),
                        'ends_at' => $endTime->toIso8601String(),
                        'duration' => $duration,
                        'total_requests' => $totalRequests,
                        'concurrency' => $concurrency,
                    ]),
                    'updated_at' => now(),
                ]
            );

            $this->info('✅ Chaos test configuration set');
            $this->newLine();

            // Execute load test
            $testResults = $this->executeLoadTest(
                $baseUrl,
                $totalRequests,
                $concurrency,
                $duration,
                $failSecureService
            );

            // End chaos test
            $this->newLine();
            $this->info('🔄 Ending chaos simulation...');

            // Update chaos test state
            DB::table('system_health_state')->updateOrInsert(
                ['component' => 'chaos_test'],
                [
                    'state' => 'inactive',
                    'metadata' => json_encode([
                        'type' => 'high_load',
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
                'system',
                'High load simulation completed successfully',
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

            return Command::FAILURE;
        }
    }

    /**
     * Execute the load test
     */
    protected function executeLoadTest(
        string $baseUrl,
        int $totalRequests,
        int $concurrency,
        int $duration,
        FailSecureService $failSecureService
    ): array {
        $this->info('🔥 Sending requests...');
        
        $results = [
            'total_sent' => 0,
            'success' => 0,
            'rate_limited' => 0,
            'errors' => 0,
            'status_codes' => [],
            'response_times' => [],
            'rate_limit_working' => false,
        ];

        $progressBar = $this->output->createProgressBar($totalRequests);
        $progressBar->start();

        $startTime = microtime(true);
        $batches = ceil($totalRequests / $concurrency);

        for ($batch = 0; $batch < $batches; $batch++) {
            $batchSize = min($concurrency, $totalRequests - ($batch * $concurrency));
            $promises = [];

            // Create concurrent requests
            for ($i = 0; $i < $batchSize; $i++) {
                $promises[] = $this->sendHealthCheckRequest($baseUrl);
            }

            // Wait for all requests in this batch to complete
            foreach ($promises as $promise) {
                try {
                    $response = $promise;
                    $results['total_sent']++;
                    
                    $statusCode = $response['status'];
                    $results['status_codes'][$statusCode] = ($results['status_codes'][$statusCode] ?? 0) + 1;
                    $results['response_times'][] = $response['time'];

                    if ($statusCode === 200) {
                        $results['success']++;
                    } elseif ($statusCode === 429) {
                        $results['rate_limited']++;
                        $results['rate_limit_working'] = true;
                    } else {
                        $results['errors']++;
                    }
                } catch (\Exception $e) {
                    $results['errors']++;
                }

                $progressBar->advance();
            }

            // Small delay between batches to avoid overwhelming the system
            usleep(100000); // 100ms

            // Log status every 10 batches
            if ($batch > 0 && $batch % 10 === 0) {
                $this->logChaosStatus($failSecureService, $results);
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        $endTime = microtime(true);
        $results['duration'] = round($endTime - $startTime, 2);
        $results['requests_per_second'] = round($results['total_sent'] / $results['duration'], 2);
        
        if (!empty($results['response_times'])) {
            $results['avg_response_time'] = round(array_sum($results['response_times']) / count($results['response_times']), 2);
            $results['max_response_time'] = round(max($results['response_times']), 2);
            $results['min_response_time'] = round(min($results['response_times']), 2);
        }

        return $results;
    }

    /**
     * Send a health check request
     */
    protected function sendHealthCheckRequest(string $baseUrl): array
    {
        $start = microtime(true);
        
        try {
            $response = Http::timeout(5)->get($baseUrl . '/health');
            $time = (microtime(true) - $start) * 1000; // Convert to ms

            return [
                'status' => $response->status(),
                'time' => $time,
            ];
        } catch (\Exception $e) {
            $time = (microtime(true) - $start) * 1000;
            
            return [
                'status' => 0,
                'time' => $time,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Show expected behavior during chaos test
     */
    protected function showExpectedBehavior(int $totalRequests, int $concurrency): void
    {
        $this->info('📋 Expected Behavior During High Load Simulation:');
        $this->newLine();

        $this->line('   <fg=yellow>1. Load Characteristics</fg=yellow>');
        $this->line("      - Total requests: {$totalRequests}");
        $this->line("      - Concurrent requests: {$concurrency}");
        $this->line('      - Target: Health check endpoint');
        $this->newLine();

        $this->line('   <fg=yellow>2. Rate Limiting</fg=yellow>');
        $this->line('      - Rate limiter should activate');
        $this->line('      - Some requests will receive 429 (Too Many Requests)');
        $this->line('      - This is EXPECTED and CORRECT behavior');
        $this->line('      - Proves rate limiting is working');
        $this->newLine();

        $this->line('   <fg=yellow>3. System Stability</fg=yellow>');
        $this->line('      - System should remain responsive');
        $this->line('      - No crashes or timeouts');
        $this->line('      - Database connections managed properly');
        $this->line('      - Memory usage should be stable');
        $this->newLine();

        $this->line('   <fg=yellow>4. Performance Metrics</fg=yellow>');
        $this->line('      - Response times tracked');
        $this->line('      - Success rate calculated');
        $this->line('      - Rate limit effectiveness measured');
        $this->line('      - Requests per second recorded');
        $this->newLine();

        $this->line('   <fg=yellow>5. Expected Outcomes</fg=yellow>');
        $this->line('      - ✅ Some 429 responses (rate limiting works)');
        $this->line('      - ✅ System stays responsive');
        $this->line('      - ✅ No 500 errors (system stable)');
        $this->line('      - ✅ Consistent response times');
        $this->newLine();
    }

    /**
     * Log chaos status periodically
     */
    protected function logChaosStatus(FailSecureService $failSecureService, array $results): void
    {
        \Log::info('Chaos Test: High load simulation in progress', [
            'requests_sent' => $results['total_sent'],
            'success' => $results['success'],
            'rate_limited' => $results['rate_limited'],
            'errors' => $results['errors'],
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

        $this->line('   Performance:');
        $this->line("      - Duration: <fg=cyan>{$testResults['duration']}s</fg=cyan>");
        $this->line("      - Total requests: <fg=cyan>{$testResults['total_sent']}</fg=cyan>");
        $this->line("      - Requests/second: <fg=cyan>{$testResults['requests_per_second']}</fg=cyan>");
        $this->newLine();

        $this->line('   Response Times:');
        if (isset($testResults['avg_response_time'])) {
            $this->line("      - Average: <fg=cyan>{$testResults['avg_response_time']}ms</fg=cyan>");
            $this->line("      - Min: <fg=cyan>{$testResults['min_response_time']}ms</fg=cyan>");
            $this->line("      - Max: <fg=cyan>{$testResults['max_response_time']}ms</fg=cyan>");
        }
        $this->newLine();

        $this->line('   Results:');
        $this->line("      - Success (200): <fg=green>{$testResults['success']}</fg=green>");
        $this->line("      - Rate Limited (429): <fg=yellow>{$testResults['rate_limited']}</fg=yellow>");
        $this->line("      - Errors: <fg=red>{$testResults['errors']}</fg=red>");
        $this->newLine();

        $this->line('   Status Codes:');
        foreach ($testResults['status_codes'] as $code => $count) {
            $color = $code === 200 ? 'green' : ($code === 429 ? 'yellow' : 'red');
            $this->line("      - {$code}: <fg={$color}>{$count}</fg={$color}>");
        }
        $this->newLine();

        // Rate limiting assessment
        if ($testResults['rate_limit_working']) {
            $this->info('   ✅ Rate Limiting: WORKING');
            $this->line('      Rate limiter successfully blocked excessive requests');
        } else {
            $this->warn('   ⚠️  Rate Limiting: NOT TRIGGERED');
            $this->line('      Consider increasing request count or concurrency');
        }
        $this->newLine();

        // System stability assessment
        $errorRate = ($testResults['errors'] / max($testResults['total_sent'], 1)) * 100;
        if ($errorRate < 5) {
            $this->info('   ✅ System Stability: EXCELLENT');
            $this->line("      Error rate: {$errorRate}%");
        } elseif ($errorRate < 10) {
            $this->warn('   ⚠️  System Stability: ACCEPTABLE');
            $this->line("      Error rate: {$errorRate}%");
        } else {
            $this->error('   ❌ System Stability: POOR');
            $this->line("      Error rate: {$errorRate}%");
        }
        $this->newLine();

        $this->line('   Current State:');
        $this->line('      - System state: <fg=green>'.$failSecureService->getSystemState().'</fg=green>');
        $this->line('      - Degraded mode: '.($failSecureService->isDegraded() ? '<fg=yellow>Yes</fg=yellow>' : '<fg=green>No</fg=green>'));

        $this->newLine();
        $this->info('💡 Review the logs and monitoring dashboards for detailed analysis.');
    }
}
