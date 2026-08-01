<?php

namespace App\Console\Commands;

use App\Services\CacheStampedeMetricsService;
use Illuminate\Console\Command;

/**
 * Monitor Cache Metrics Command
 * 
 * Displays cache stampede metrics and statistics
 */
class MonitorCacheMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cache:monitor 
                            {key? : The cache key to monitor}
                            {--all : Show metrics for all keys}
                            {--clear : Clear metrics for the specified key}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Monitor cache stampede metrics and statistics';

    /**
     * Execute the console command.
     */
    public function handle(CacheStampedeMetricsService $metricsService): int
    {
        $key = $this->argument('key');
        $showAll = $this->option('all');
        $clear = $this->option('clear');

        if ($clear && $key) {
            $metricsService->clearMetrics($key);
            $this->info("Metrics cleared for cache key: {$key}");
            return Command::SUCCESS;
        }

        if ($showAll) {
            $this->displayAggregatedMetrics($metricsService);
            return Command::SUCCESS;
        }

        if ($key) {
            $this->displayKeyMetrics($metricsService, $key);
            return Command::SUCCESS;
        }

        $this->error('Please specify a cache key or use --all option');
        return Command::FAILURE;
    }

    /**
     * Display metrics for a specific cache key
     */
    protected function displayKeyMetrics(CacheStampedeMetricsService $metricsService, string $key): void
    {
        $this->info("Cache Metrics for: {$key}");
        $this->newLine();

        $metrics = $metricsService->getAllMetrics($key);

        // Hit/Miss Rates
        $this->line('<fg=cyan>Hit/Miss Rates:</>');
        $hitMiss = $metrics['hit_miss_rates'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Hits', $hitMiss['hits']],
                ['Misses', $hitMiss['misses']],
                ['Total Requests', $hitMiss['total_requests']],
                ['Hit Rate', number_format($hitMiss['hit_rate'], 2) . '%'],
                ['Miss Rate', number_format($hitMiss['miss_rate'], 2) . '%'],
            ]
        );
        $this->newLine();

        // Stale Serving
        $this->line('<fg=cyan>Stale Cache Serving:</>');
        $stale = $metrics['stale_serving'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Stale Served', $stale['stale_served']],
                ['Total Misses', $stale['total_misses']],
                ['Stale Serving Rate', number_format($stale['stale_serving_rate'], 2) . '%'],
            ]
        );
        $this->newLine();

        // Lock Contention
        $this->line('<fg=cyan>Lock Contention:</>');
        $contention = $metrics['lock_contention'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Lock Contentions', $contention['lock_contentions']],
                ['Lock Acquisitions', $contention['lock_acquisitions']],
                ['Contention Rate', number_format($contention['contention_rate'], 2) . '%'],
            ]
        );
        $this->newLine();

        // Regeneration Stats
        $this->line('<fg=cyan>Regeneration Statistics:</>');
        $regen = $metrics['regeneration_stats'];
        $this->table(
            ['Metric', 'Value'],
            [
                ['Count', $regen['count']],
                ['Min Time', number_format($regen['min_time'], 4) . 's'],
                ['Max Time', number_format($regen['max_time'], 4) . 's'],
                ['Avg Time', number_format($regen['avg_time'] ?? 0, 4) . 's'],
            ]
        );
        $this->newLine();

        // Stampede Frequency
        $stampedeFreq = $metrics['stampede_frequency'];
        if ($stampedeFreq > 0) {
            $this->warn("⚠️  Stampede detected {$stampedeFreq} time(s) in the last hour");
        } else {
            $this->info('✓ No stampedes detected in the last hour');
        }
    }

    /**
     * Display aggregated metrics for all keys
     */
    protected function displayAggregatedMetrics(CacheStampedeMetricsService $metricsService): void
    {
        $this->info('Aggregated Cache Metrics');
        $this->newLine();

        $metrics = $metricsService->getAggregatedMetrics();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total Cache Operations', $metrics['total_cache_operations']],
                ['Total Regenerations', $metrics['total_regenerations']],
                ['Total Stampedes Detected', $metrics['total_stampedes_detected']],
                ['Average Hit Rate', number_format($metrics['average_hit_rate'], 2) . '%'],
            ]
        );

        if (!empty($metrics['hot_keys'])) {
            $this->newLine();
            $this->line('<fg=cyan>Hot Keys (High Contention):</>');
            $this->table(['Cache Key', 'Contention Count'], $metrics['hot_keys']);
        }
    }
}
