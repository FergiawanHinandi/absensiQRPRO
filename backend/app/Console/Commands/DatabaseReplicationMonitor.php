<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class DatabaseReplicationMonitor extends Command
{
    protected $signature = 'db:monitor 
                            {--interval=30 : Check interval in seconds}
                            {--auto-failover : Automatically failover on primary failure}
                            {--alert-webhook= : Webhook URL for alerts}
                            {--max-lag=60 : Maximum acceptable replication lag in seconds}';

    protected $description = 'Monitor database replication health continuously';

    private int $failureCount = 0;
    private int $maxFailuresBeforeAlert = 3;

    public function handle(): int
    {
        $interval = (int) $this->option('interval');
        $autoFailover = $this->option('auto-failover');
        $maxLag = (int) $this->option('max-lag');

        $this->info("🔍 Starting Database Replication Monitor");
        $this->line("   Interval: {$interval}s");
        $this->line("   Auto-failover: " . ($autoFailover ? 'ENABLED' : 'disabled'));
        $this->line("   Max lag threshold: {$maxLag}s");
        $this->newLine();

        while (true) {
            $status = $this->performHealthCheck($maxLag);
            
            $this->handleStatus($status, $autoFailover);
            
            sleep($interval);
        }

        return self::SUCCESS;
    }

    private function performHealthCheck(int $maxLag): array
    {
        $status = [
            'timestamp' => now()->toIso8601String(),
            'primary' => $this->checkPrimary(),
            'replica' => $this->checkReplica(),
            'replication_lag' => null,
            'overall' => 'healthy',
        ];

        // Check replication lag if both are healthy
        if ($status['primary']['healthy'] && $status['replica']['healthy']) {
            $status['replication_lag'] = $this->getReplicationLag();
            
            if ($status['replication_lag'] > $maxLag) {
                $status['overall'] = 'warning';
            }
        } elseif (!$status['primary']['healthy']) {
            $status['overall'] = 'critical';
        } elseif (!$status['replica']['healthy']) {
            $status['overall'] = 'degraded';
        }

        // Cache status for external monitoring
        Cache::put('db:monitor:status', $status, now()->addMinutes(5));

        return $status;
    }

    private function checkPrimary(): array
    {
        try {
            $start = microtime(true);
            DB::connection('pgsql')->getPdo();
            DB::connection('pgsql')->select('SELECT 1');
            
            return [
                'healthy' => true,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function checkReplica(): array
    {
        if (!env('DB_REPLICA_ENABLED', false)) {
            return [
                'healthy' => true,
                'latency_ms' => null,
                'error' => 'Replica not configured',
                'skipped' => true,
            ];
        }

        try {
            $replicaHost = env('DB_REPLICA_HOST');
            $replicaPort = env('DB_REPLICA_PORT', 5432);
            
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $replicaHost,
                $replicaPort,
                env('DB_DATABASE')
            );

            $start = microtime(true);
            $pdo = new \PDO($dsn, env('DB_USERNAME'), env('DB_PASSWORD'), [
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            $pdo->query('SELECT 1');
            
            return [
                'healthy' => true,
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'latency_ms' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    private function getReplicationLag(): ?float
    {
        try {
            $result = DB::connection('pgsql')->selectOne("
                SELECT 
                    EXTRACT(EPOCH FROM (now() - pg_last_xact_replay_timestamp())) AS lag_seconds
                FROM pg_stat_replication
                LIMIT 1
            ");

            return $result->lag_seconds ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function handleStatus(array $status, bool $autoFailover): void
    {
        $icon = match ($status['overall']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'degraded' => '🟡',
            'critical' => '❌',
            default => '❓',
        };

        $lagInfo = $status['replication_lag'] !== null 
            ? " | Lag: {$status['replication_lag']}s" 
            : '';

        $this->line(sprintf(
            "[%s] %s %s | Primary: %sms | Replica: %sms%s",
            now()->format('H:i:s'),
            $icon,
            strtoupper($status['overall']),
            $status['primary']['latency_ms'] ?? 'DOWN',
            $status['replica']['latency_ms'] ?? ($status['replica']['skipped'] ?? false ? 'N/A' : 'DOWN'),
            $lagInfo
        ));

        // Handle critical status
        if ($status['overall'] === 'critical') {
            $this->failureCount++;
            
            if ($this->failureCount >= $this->maxFailuresBeforeAlert) {
                $this->sendAlert($status);
                
                if ($autoFailover && $status['replica']['healthy']) {
                    $this->warn('⚠️  Auto-failover triggered!');
                    $this->call('db:failover', ['action' => 'promote', '--force' => true]);
                    $this->failureCount = 0;
                }
            }
        } else {
            $this->failureCount = 0;
        }

        // Log status
        if ($status['overall'] !== 'healthy') {
            Log::warning('Database replication status: ' . $status['overall'], $status);
        }
    }

    private function sendAlert(array $status): void
    {
        $webhook = $this->option('alert-webhook') ?? config('services.slack.security_webhook_url');
        
        if (!$webhook) {
            return;
        }

        $message = sprintf(
            "🚨 DATABASE ALERT: %s\nPrimary: %s\nReplica: %s\nTime: %s",
            strtoupper($status['overall']),
            $status['primary']['healthy'] ? 'OK' : 'DOWN - ' . $status['primary']['error'],
            $status['replica']['healthy'] ? 'OK' : 'DOWN - ' . ($status['replica']['error'] ?? 'Unknown'),
            $status['timestamp']
        );

        try {
            Http::post($webhook, [
                'text' => $message,
                'attachments' => [
                    [
                        'color' => 'danger',
                        'title' => 'Database Health Alert',
                        'fields' => [
                            ['title' => 'Status', 'value' => $status['overall'], 'short' => true],
                            ['title' => 'Primary', 'value' => $status['primary']['healthy'] ? '✅ OK' : '❌ DOWN', 'short' => true],
                            ['title' => 'Replica', 'value' => $status['replica']['healthy'] ? '✅ OK' : '❌ DOWN', 'short' => true],
                        ],
                    ],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send database alert', ['error' => $e->getMessage()]);
        }
    }
}
