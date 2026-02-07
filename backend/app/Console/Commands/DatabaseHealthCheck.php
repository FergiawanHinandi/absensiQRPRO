<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DatabaseHealthCheck extends Command
{
    protected $signature = 'db:health-check 
                            {--primary : Check primary only}
                            {--replica : Check replica only}
                            {--json : Output as JSON}';

    protected $description = 'Check health of primary and replica databases';

    public function handle(): int
    {
        $results = [
            'timestamp' => now()->toIso8601String(),
            'primary' => null,
            'replica' => null,
            'replication_lag' => null,
            'overall_status' => 'healthy',
        ];

        // Check Primary
        if (!$this->option('replica')) {
            $results['primary'] = $this->checkConnection('write');
        }

        // Check Replica
        if (!$this->option('primary') && env('DB_REPLICA_ENABLED', false)) {
            $results['replica'] = $this->checkConnection('read');
            
            // Check replication lag
            if ($results['primary']['status'] === 'healthy' && $results['replica']['status'] === 'healthy') {
                $results['replication_lag'] = $this->checkReplicationLag();
            }
        }

        // Determine overall status
        if ($results['primary'] && $results['primary']['status'] !== 'healthy') {
            $results['overall_status'] = 'critical';
        } elseif ($results['replica'] && $results['replica']['status'] !== 'healthy') {
            $results['overall_status'] = 'degraded';
        } elseif ($results['replication_lag'] && $results['replication_lag']['lag_seconds'] > 30) {
            $results['overall_status'] = 'warning';
        }

        // Cache result for monitoring (silently fail if cache unavailable)
        try {
            Cache::put('db:health:status', $results, now()->addMinutes(5));
        } catch (\Exception $e) {
            // Cache not available, continue without caching
        }

        // Output
        if ($this->option('json')) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT));
        } else {
            $this->displayResults($results);
        }

        // Log if unhealthy
        if ($results['overall_status'] !== 'healthy') {
            Log::warning('Database health check: ' . $results['overall_status'], $results);
        }

        return match ($results['overall_status']) {
            'healthy' => self::SUCCESS,
            'warning', 'degraded' => 1,
            'critical' => 2,
            default => self::FAILURE,
        };
    }

    private function checkConnection(string $type): array
    {
        $result = [
            'type' => $type,
            'status' => 'unknown',
            'latency_ms' => null,
            'error' => null,
        ];

        try {
            $start = microtime(true);
            
            // Force connection type
            $connection = DB::connection('pgsql');
            
            if ($type === 'read' && env('DB_REPLICA_ENABLED', false)) {
                // Force read from replica
                $connection->select('SELECT 1');
            } else {
                // Force write connection check
                $connection->statement('SELECT 1');
            }
            
            $result['latency_ms'] = round((microtime(true) - $start) * 1000, 2);
            $result['status'] = 'healthy';
            
            // Get server info
            $version = $connection->selectOne('SELECT version()');
            $result['version'] = $version->version ?? 'unknown';
            
        } catch (\Exception $e) {
            $result['status'] = 'unhealthy';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function checkReplicationLag(): array
    {
        $result = [
            'lag_seconds' => null,
            'lag_bytes' => null,
            'status' => 'unknown',
        ];

        try {
            // Check replication status on primary
            $primaryStatus = DB::connection('pgsql')->selectOne("
                SELECT 
                    pg_current_wal_lsn() as current_lsn,
                    pg_wal_lsn_diff(pg_current_wal_lsn(), replay_lsn) as lag_bytes
                FROM pg_stat_replication
                LIMIT 1
            ");

            if ($primaryStatus) {
                $result['lag_bytes'] = $primaryStatus->lag_bytes ?? 0;
                // Estimate lag in seconds (rough: 16MB/s throughput)
                $result['lag_seconds'] = $result['lag_bytes'] > 0 
                    ? round($result['lag_bytes'] / (16 * 1024 * 1024), 2) 
                    : 0;
                $result['status'] = $result['lag_seconds'] < 30 ? 'acceptable' : 'high';
            } else {
                $result['status'] = 'no_replication';
            }

        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    private function displayResults(array $results): void
    {
        $this->info("=== Database Health Check ===");
        $this->newLine();

        // Overall status
        $statusIcon = match ($results['overall_status']) {
            'healthy' => '✅',
            'warning' => '⚠️',
            'degraded' => '🟡',
            'critical' => '❌',
            default => '❓',
        };
        $this->line("{$statusIcon} Overall Status: {$results['overall_status']}");
        $this->newLine();

        // Primary
        if ($results['primary']) {
            $icon = $results['primary']['status'] === 'healthy' ? '✅' : '❌';
            $this->line("{$icon} Primary (Write):");
            $this->line("   Status: {$results['primary']['status']}");
            $this->line("   Latency: {$results['primary']['latency_ms']}ms");
            if ($results['primary']['error']) {
                $this->error("   Error: {$results['primary']['error']}");
            }
            $this->newLine();
        }

        // Replica
        if ($results['replica']) {
            $icon = $results['replica']['status'] === 'healthy' ? '✅' : '❌';
            $this->line("{$icon} Replica (Read):");
            $this->line("   Status: {$results['replica']['status']}");
            $this->line("   Latency: {$results['replica']['latency_ms']}ms");
            if ($results['replica']['error']) {
                $this->error("   Error: {$results['replica']['error']}");
            }
            $this->newLine();
        }

        // Replication Lag
        if ($results['replication_lag']) {
            $lagIcon = $results['replication_lag']['status'] === 'acceptable' ? '✅' : '⚠️';
            $this->line("{$lagIcon} Replication Lag:");
            $this->line("   Lag: {$results['replication_lag']['lag_seconds']}s");
            $this->line("   Bytes behind: " . number_format($results['replication_lag']['lag_bytes'] ?? 0));
        }
    }
}
