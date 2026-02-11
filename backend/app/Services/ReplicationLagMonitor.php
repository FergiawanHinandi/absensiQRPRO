<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Replication Lag Monitor
 * 
 * Monitors MySQL replication lag and provides fallback logic
 * 
 * @package App\Services
 */
class ReplicationLagMonitor
{
    /**
     * Replication lag threshold in seconds
     */
    private const LAG_THRESHOLD = 2.0;

    /**
     * Cache key for lag metrics
     */
    private const CACHE_KEY = 'replication_lag';

    /**
     * Cache TTL in seconds
     */
    private const CACHE_TTL = 5;

    /**
     * Get current replication lag in seconds
     * 
     * @return float
     */
    public static function getLag(): float
    {
        // Check cache first to avoid excessive queries
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return (float) $cached;
        }

        try {
            // Query replication status
            $result = DB::connection('mysql_read')
                ->select("SHOW SLAVE STATUS");

            if (empty($result)) {
                Log::warning('No replication status found - not a replica?');
                return 0;
            }

            $status = $result[0];
            $lag = (float) ($status->Seconds_Behind_Master ?? 0);

            // Cache the result
            Cache::put(self::CACHE_KEY, $lag, self::CACHE_TTL);

            // Log if lag is high
            if ($lag > self::LAG_THRESHOLD) {
                Log::warning('Replication lag high', [
                    'lag_seconds' => $lag,
                    'threshold' => self::LAG_THRESHOLD,
                    'io_running' => $status->Slave_IO_Running ?? 'Unknown',
                    'sql_running' => $status->Slave_SQL_Running ?? 'Unknown',
                ]);
            }

            return $lag;
        } catch (\Exception $e) {
            Log::error('Failed to get replication lag', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Assume high lag on error (fail-safe)
            return 999.0;
        }
    }

    /**
     * Check if replication lag is acceptable
     * 
     * @param float|null $threshold Custom threshold (default: 2.0 seconds)
     * @return bool
     */
    public static function isAcceptable(?float $threshold = null): bool
    {
        $threshold = $threshold ?? self::LAG_THRESHOLD;
        $lag = self::getLag();

        return $lag <= $threshold;
    }

    /**
     * Get the appropriate read connection based on replication lag
     * 
     * Falls back to primary if lag is too high
     * 
     * @return string Connection name ('mysql_read' or 'mysql')
     */
    public static function getReadConnection(): string
    {
        if (self::isAcceptable()) {
            return 'mysql_read';
        }

        Log::info('Falling back to primary database due to high replication lag', [
            'lag_seconds' => self::getLag(),
        ]);

        return 'mysql'; // Fallback to primary
    }

    /**
     * Get detailed replication status
     * 
     * @return array
     */
    public static function getStatus(): array
    {
        try {
            $result = DB::connection('mysql_read')
                ->select("SHOW SLAVE STATUS");

            if (empty($result)) {
                return [
                    'is_replica' => false,
                    'status' => 'not_a_replica',
                ];
            }

            $status = $result[0];

            return [
                'is_replica' => true,
                'lag_seconds' => (float) ($status->Seconds_Behind_Master ?? 0),
                'io_running' => $status->Slave_IO_Running === 'Yes',
                'sql_running' => $status->Slave_SQL_Running === 'Yes',
                'last_error' => $status->Last_Error ?? null,
                'master_host' => $status->Master_Host ?? null,
                'master_port' => $status->Master_Port ?? null,
                'is_healthy' => $status->Slave_IO_Running === 'Yes' 
                             && $status->Slave_SQL_Running === 'Yes'
                             && ($status->Seconds_Behind_Master ?? 0) < self::LAG_THRESHOLD,
            ];
        } catch (\Exception $e) {
            return [
                'is_replica' => false,
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Check if replica is healthy
     * 
     * @return bool
     */
    public static function isHealthy(): bool
    {
        $status = self::getStatus();

        return $status['is_healthy'] ?? false;
    }

    /**
     * Clear lag cache
     * 
     * Useful for forcing a fresh check
     * 
     * @return void
     */
    public static function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Get lag metrics for monitoring
     * 
     * @return array
     */
    public static function getMetrics(): array
    {
        $lag = self::getLag();
        $status = self::getStatus();

        return [
            'lag_seconds' => $lag,
            'is_acceptable' => $lag <= self::LAG_THRESHOLD,
            'threshold' => self::LAG_THRESHOLD,
            'is_healthy' => $status['is_healthy'] ?? false,
            'io_running' => $status['io_running'] ?? false,
            'sql_running' => $status['sql_running'] ?? false,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
