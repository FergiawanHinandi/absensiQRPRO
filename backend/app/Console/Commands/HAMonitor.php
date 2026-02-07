<?php

namespace App\Console\Commands;

use App\Services\HighAvailabilityMonitorService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class HAMonitor extends Command
{
    protected $signature = 'ha:monitor 
                            {--daemon : Run continuously as a daemon}
                            {--interval=30 : Check interval in seconds}
                            {--auto-failover : Automatically execute failover on critical status}
                            {--json : Output as JSON}
                            {--alert : Send alerts on warning/critical status}
                            {--component= : Check specific component only}';

    protected $description = 'High Availability monitoring - check all system components health';

    private HighAvailabilityMonitorService $service;
    private int $consecutiveFailures = 0;
    private int $maxFailuresBeforeFailover = 3;

    public function __construct(HighAvailabilityMonitorService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    public function handle(): int
    {
        if ($this->option('daemon')) {
            return $this->runDaemon();
        }

        return $this->runOnce();
    }

    /**
     * Run a single health check
     */
    private function runOnce(): int
    {
        $component = $this->option('component');
        
        if ($component) {
            $result = $this->checkComponent($component);
        } else {
            $result = $this->service->runAllChecks();
        }

        // Output
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->displayResults($result);
        }

        // Send alerts if requested
        if ($this->option('alert') && $result['overall_status'] !== 'healthy') {
            $this->service->sendAlert($result);
            $this->info('📬 Alert sent');
        }

        // Return appropriate exit code
        return match ($result['overall_status']) {
            'healthy' => self::SUCCESS,
            'warning' => 1,
            'critical' => 2,
            default => self::FAILURE,
        };
    }

    /**
     * Run as a daemon with continuous monitoring
     */
    private function runDaemon(): int
    {
        $interval = (int) $this->option('interval');
        $autoFailover = $this->option('auto-failover');

        $this->info("🔍 Starting HA Monitor Daemon");
        $this->line("   Interval: {$interval}s");
        $this->line("   Auto-failover: " . ($autoFailover ? 'ENABLED ⚡' : 'disabled'));
        $this->line("   Alerts: " . ($this->option('alert') ? 'ENABLED' : 'disabled'));
        $this->newLine();

        while (true) {
            $result = $this->service->runAllChecks();
            
            $this->outputDaemonStatus($result);
            
            // Handle alerts and failover
            $this->handleDaemonResult($result, $autoFailover);
            
            sleep($interval);
        }

        return self::SUCCESS;
    }

    /**
     * Check specific component
     */
    private function checkComponent(string $component): array
    {
        $result = match ($component) {
            'app', 'app_server' => ['component' => 'app_server', 'data' => $this->service->checkAppServer()],
            'db', 'database' => ['component' => 'database', 'data' => $this->service->checkDatabase()],
            'redis' => ['component' => 'redis', 'data' => $this->service->checkRedis()],
            'queue' => ['component' => 'queue', 'data' => $this->service->checkQueue()],
            'storage' => ['component' => 'storage', 'data' => $this->service->checkStorage()],
            default => null,
        };

        if (!$result) {
            return [
                'overall_status' => 'error',
                'message' => "Unknown component: {$component}",
            ];
        }

        return [
            'timestamp' => now()->toIso8601String(),
            'overall_status' => $result['data']['status'],
            'components' => [
                $result['component'] => $result['data'],
            ],
            'alerts' => $result['data']['status'] !== 'healthy' ? [
                [
                    'component' => $result['component'],
                    'severity' => $result['data']['status'],
                    'message' => $result['data']['message'] ?? "{$component} is {$result['data']['status']}",
                ],
            ] : [],
        ];
    }

    /**
     * Display results in table format
     */
    private function displayResults(array $result): void
    {
        $this->info("=== High Availability Status ===");
        $this->line("Timestamp: " . $result['timestamp']);
        $this->newLine();

        // Overall status
        $statusIcon = match ($result['overall_status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'critical' => '❌',
            default => '❓',
        };
        $this->line("{$statusIcon} Overall: " . strtoupper($result['overall_status']));
        $this->newLine();

        // Components table
        $rows = [];
        foreach ($result['components'] ?? [] as $name => $component) {
            $icon = match ($component['status']) {
                'healthy' => '✅',
                'warning' => '⚠️',
                'critical' => '❌',
                default => '❓',
            };
            
            $details = $this->formatComponentDetails($name, $component);
            $rows[] = [ucfirst($name), $icon . ' ' . $component['status'], $details];
        }

        $this->table(['Component', 'Status', 'Details'], $rows);

        // Alerts
        if (!empty($result['alerts'])) {
            $this->newLine();
            $this->warn("⚠️  Alerts:");
            foreach ($result['alerts'] as $alert) {
                $this->line("   [{$alert['severity']}] {$alert['component']}: {$alert['message']}");
            }
        }
    }

    /**
     * Format component details for display
     */
    private function formatComponentDetails(string $name, array $component): string
    {
        $details = [];

        switch ($name) {
            case 'app_server':
                if (isset($component['checks']['disk'])) {
                    $details[] = "Disk: {$component['checks']['disk']['used_percent']}%";
                }
                if (isset($component['checks']['self_check']['memory_mb'])) {
                    $details[] = "Mem: {$component['checks']['self_check']['memory_mb']}MB";
                }
                break;

            case 'database':
                if (isset($component['checks']['primary']['latency_ms'])) {
                    $details[] = "Primary: {$component['checks']['primary']['latency_ms']}ms";
                }
                if (isset($component['checks']['replication_lag']['lag_seconds'])) {
                    $details[] = "Lag: {$component['checks']['replication_lag']['lag_seconds']}s";
                }
                if (isset($component['failover_action'])) {
                    $details[] = "Action: {$component['failover_action']}";
                }
                break;

            case 'redis':
                if (isset($component['checks']['role']['role'])) {
                    $details[] = "Role: {$component['checks']['role']['role']}";
                }
                if (isset($component['checks']['memory']['used_percent'])) {
                    $details[] = "Mem: {$component['checks']['memory']['used_percent']}%";
                }
                break;

            case 'queue':
                if (isset($component['checks']['backlog']['pending_jobs'])) {
                    $details[] = "Pending: {$component['checks']['backlog']['pending_jobs']}";
                }
                if (isset($component['checks']['failed_jobs']['last_hour'])) {
                    $details[] = "Failed/hr: {$component['checks']['failed_jobs']['last_hour']}";
                }
                break;

            case 'storage':
                $local = $component['checks']['local']['healthy'] ?? false;
                $details[] = "Local: " . ($local ? 'OK' : 'FAIL');
                if (isset($component['checks']['cloud'])) {
                    $cloud = $component['checks']['cloud']['healthy'] ?? false;
                    $details[] = "Cloud: " . ($cloud ? 'OK' : 'FAIL');
                }
                break;
        }

        return implode(' | ', $details) ?: ($component['message'] ?? 'OK');
    }

    /**
     * Output status in daemon mode (single line)
     */
    private function outputDaemonStatus(array $result): void
    {
        $icon = match ($result['overall_status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'critical' => '❌',
            default => '❓',
        };

        $components = [];
        foreach ($result['components'] as $name => $component) {
            $cIcon = match ($component['status']) {
                'healthy' => '✓',
                'warning' => '⚡',
                'critical' => '✗',
                default => '?',
            };
            $components[] = "{$name}:{$cIcon}";
        }

        $this->line(sprintf(
            "[%s] %s %s | %s",
            now()->format('H:i:s'),
            $icon,
            strtoupper($result['overall_status']),
            implode(' ', $components)
        ));
    }

    /**
     * Handle daemon result - alerts and auto-failover
     */
    private function handleDaemonResult(array $result, bool $autoFailover): void
    {
        if ($result['overall_status'] === 'healthy') {
            $this->consecutiveFailures = 0;
            return;
        }

        // Send alert
        if ($this->option('alert')) {
            $this->service->sendAlert($result);
        }

        // Track failures for auto-failover
        if ($result['overall_status'] === 'critical') {
            $this->consecutiveFailures++;

            // Log critical status
            Log::critical('HA Monitor: Critical status detected', [
                'consecutive_failures' => $this->consecutiveFailures,
                'status' => $result,
            ]);

            // Auto-failover after consecutive failures
            if ($autoFailover && $this->consecutiveFailures >= $this->maxFailuresBeforeFailover) {
                $this->executeAutoFailover($result);
            }
        }
    }

    /**
     * Execute auto-failover based on failing components
     */
    private function executeAutoFailover(array $result): void
    {
        foreach ($result['components'] as $component => $status) {
            if ($status['status'] === 'critical' && isset($status['failover_action'])) {
                $this->warn("⚡ Auto-failover triggered for {$component}");
                
                $failoverResult = $this->service->executeFailover($component, $status['failover_action']);
                
                if ($failoverResult['success']) {
                    $this->info("✅ Failover successful: {$failoverResult['message']}");
                    Log::info('Auto-failover executed', [
                        'component' => $component,
                        'action' => $status['failover_action'],
                        'result' => $failoverResult,
                    ]);
                } else {
                    $this->error("❌ Failover failed: {$failoverResult['message']}");
                    Log::error('Auto-failover failed', [
                        'component' => $component,
                        'action' => $status['failover_action'],
                        'result' => $failoverResult,
                    ]);
                }

                // Reset failure counter after attempting failover
                $this->consecutiveFailures = 0;
            }
        }
    }
}
