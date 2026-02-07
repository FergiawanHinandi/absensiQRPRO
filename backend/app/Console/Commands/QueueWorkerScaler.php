<?php

namespace App\Console\Commands;

use App\Services\QueueWorkerScalerService;
use Illuminate\Console\Command;

class QueueWorkerScaler extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:scale 
                            {--daemon : Run continuously}
                            {--interval=60 : Check interval in seconds (daemon mode)}
                            {--dry-run : Simulate scaling without executing}
                            {--json : Output as JSON}
                            {--status : Show current status only}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Auto-scale queue workers based on queue length';

    public function __construct(
        protected QueueWorkerScalerService $scaler
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('status')) {
            return $this->showStatus();
        }

        if ($this->option('daemon')) {
            return $this->runDaemon();
        }

        return $this->runOnce();
    }

    /**
     * Run single evaluation
     */
    protected function runOnce(): int
    {
        $result = $this->scaler->evaluate();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
            return 0;
        }

        $this->displayResult($result);
        return 0;
    }

    /**
     * Run in daemon mode
     */
    protected function runDaemon(): int
    {
        $interval = (int) $this->option('interval');
        
        $this->info("Queue Worker Scaler - Daemon Mode");
        $this->info("Interval: {$interval}s | Press Ctrl+C to stop");
        $this->newLine();

        while (true) {
            $result = $this->scaler->evaluate();
            
            if ($this->option('json')) {
                $this->line(json_encode($result));
            } else {
                $this->displayCompactResult($result);
            }

            sleep($interval);
        }

        return 0;
    }

    /**
     * Show current status
     */
    protected function showStatus(): int
    {
        $metrics = $this->scaler->gatherMetrics();
        $workers = $this->scaler->getCurrentWorkerCount();
        $config = $this->scaler->getConfig();

        if ($this->option('json')) {
            $this->line(json_encode([
                'enabled' => $this->scaler->isEnabled(),
                'workers' => [
                    'current' => $workers,
                    'min' => $config['min_workers'],
                    'max' => $config['max_workers'],
                ],
                'metrics' => $metrics,
                'thresholds' => [
                    'scale_up' => $config['scale_up_threshold'],
                    'scale_down' => $config['scale_down_threshold'],
                    'scale_down_duration' => $config['scale_down_duration'],
                ],
            ], JSON_PRETTY_PRINT));
            return 0;
        }

        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           QUEUE WORKER AUTO-SCALER STATUS                    ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Status
        $enabled = $this->scaler->isEnabled();
        $statusColor = $enabled ? 'green' : 'red';
        $statusText = $enabled ? 'ENABLED' : 'DISABLED';
        $this->line("  Status: <fg={$statusColor};options=bold>{$statusText}</>");
        $this->newLine();

        // Workers
        $this->info('┌─ Workers ─────────────────────────────────────────────────────┐');
        $this->line("  │ Current: <fg=cyan;options=bold>{$workers}</> workers");
        $this->line("  │ Min: {$config['min_workers']} | Max: {$config['max_workers']}");
        $this->info('└───────────────────────────────────────────────────────────────┘');
        $this->newLine();

        // Thresholds
        $this->info('┌─ Scaling Rules ───────────────────────────────────────────────┐');
        $this->line("  │ Scale UP when:   >{$config['scale_up_threshold']} pending jobs");
        $this->line("  │ Scale DOWN when: <{$config['scale_down_threshold']} jobs for " . ($config['scale_down_duration'] / 60) . " min");
        $this->info('└───────────────────────────────────────────────────────────────┘');
        $this->newLine();

        // Current Metrics
        $pending = $metrics['total_pending'];
        $pendingColor = $pending > $config['scale_up_threshold'] ? 'red' 
            : ($pending < $config['scale_down_threshold'] ? 'yellow' : 'green');

        $this->info('┌─ Current Metrics ─────────────────────────────────────────────┐');
        $this->line("  │ Total Pending: <fg={$pendingColor};options=bold>{$pending}</> jobs");
        $this->line("  │ Failed Jobs:   {$metrics['failed_jobs']}");
        $this->info('└───────────────────────────────────────────────────────────────┘');
        $this->newLine();

        // Queue breakdown
        if (!empty($metrics['by_queue'])) {
            $this->info('┌─ Queue Breakdown ─────────────────────────────────────────────┐');
            $this->table(
                ['Queue', 'Pending', 'Delayed', 'Reserved'],
                collect($metrics['by_queue'])->map(function ($data, $queue) {
                    return [
                        $queue,
                        $data['pending'] ?? 0,
                        $data['delayed'] ?? 0,
                        $data['reserved'] ?? 0,
                    ];
                })->toArray()
            );
            $this->info('└───────────────────────────────────────────────────────────────┘');
        }

        return 0;
    }

    /**
     * Display full result
     */
    protected function displayResult(array $result): void
    {
        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════════════════╗');
        $this->info('║           QUEUE WORKER SCALING EVALUATION                    ║');
        $this->info('╚══════════════════════════════════════════════════════════════╝');
        $this->newLine();

        // Action
        $action = $result['action'];
        $actionColor = match ($action) {
            'scale_up' => 'green',
            'scale_down' => 'yellow',
            default => 'white',
        };
        $actionIcon = match ($action) {
            'scale_up' => '⬆️  SCALE UP',
            'scale_down' => '⬇️  SCALE DOWN',
            default => '→  NO ACTION',
        };

        $this->line("  Action: <fg={$actionColor};options=bold>{$actionIcon}</>");
        $this->line("  Reason: {$result['reason']}");
        $this->newLine();

        // Metrics
        $pending = $result['metrics']['total_pending'] ?? 0;
        $this->line("  Pending Jobs: <fg=cyan>{$pending}</>");
        
        // Workers
        if (isset($result['workers'])) {
            $current = $result['workers']['current'];
            $min = $result['workers']['min'];
            $max = $result['workers']['max'];
            $this->line("  Workers: <fg=cyan>{$current}</> (min: {$min}, max: {$max})");
        }

        // Execution result
        if (isset($result['executed'])) {
            $execStatus = $result['executed'] ? '<fg=green>SUCCESS</>' : '<fg=red>FAILED</>';
            $this->line("  Executed: {$execStatus}");
        }

        $this->newLine();
    }

    /**
     * Display compact result for daemon mode
     */
    protected function displayCompactResult(array $result): void
    {
        $time = now()->format('H:i:s');
        $pending = $result['metrics']['total_pending'] ?? 0;
        $workers = $result['workers']['current'] ?? '?';
        $action = $result['action'];

        $actionIcon = match ($action) {
            'scale_up' => '<fg=green>▲ UP</>',
            'scale_down' => '<fg=yellow>▼ DOWN</>',
            default => '<fg=gray>— HOLD</>',
        };

        $this->line("[{$time}] Jobs: {$pending} | Workers: {$workers} | {$actionIcon} | {$result['reason']}");
    }
}
