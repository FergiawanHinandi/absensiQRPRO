<?php

namespace App\Services\Resilience;

use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Database\QueryException;
use Symfony\Component\HttpFoundation\Response;

class SystemResilienceService
{
    /**
     * @var string
     */
    protected $context;

    /**
     * Execute operation with automatic fallback
     * 
     * @param string $operationName The name of the operation for logging
     * @param callable $primary The main operation to attempt
     * @param callable|null $fallback The fallback operation if primary fails
     * @param bool $failOpen Whether to allow success if primary fails and no fallback
     * @return mixed
     */
    public function executeWithFallback(string $operationName, callable $primary, ?callable $fallback = null, bool $failOpen = false)
    {
        try {
            // Check health before attempting critical external resources
            if ($this->shouldSkipPrimary($operationName)) {
                throw new Exception("Circuit Breaker Open: {$operationName}");
            }

            return $primary();

        } catch (Exception $e) {
            $this->handleFailure($operationName, $e);

            if ($fallback) {
                try {
                    return $fallback();
                } catch (Exception $fallbackError) {
                    $this->logCritical("Fallback Failed: {$operationName}", $fallbackError);
                }
            }

            if ($failOpen) {
                $this->logWarning("Fail Open triggered for {$operationName}");
                return null; // or default value
            }

            throw $e;
        }
    }

    /**
     * Check if Redis is available
     * Fallback strategy: Switch to 'array' (per request) or 'file' cache
     */
    public function redisFallback()
    {
        try {
            Redis::ping();
            return true;
        } catch (Exception $e) {
            $this->logWarning("Redis DOWN. Switching to ephemeral/file cache.");
            // Programmatically switch cache store for this request
            config(['cache.default' => 'array']); // Critical fallback: array (memory)
            config(['session.driver' => 'file']); // Session fallback
            return false;
        }
    }

    /**
     * Check Database Health (Simulate Slow DB)
     */
    public function databaseHealthCheck()
    {
        try {
            // Set stricter timeout for health check
            $pdo = DB::connection()->getPdo();
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 2); // 2 seconds timeout
            
            DB::select('SELECT 1');
            return true;
        } catch (QueryException $e) {
            $this->logCritical("Database SLOW or DOWN", $e);
            return false;
        }
    }

    /**
     * Queue Worker Fallback
     * If Queue is declared dead/full, execute job synchronously
     */
    public function queueSafeDispatch($job)
    {
        try {
            // Simulate queue health check (e.g., check Redis list length)
            // If length > 1000, consider "Dead/Backlogged"
            // dispatch($job);
            
            // For now, simple try-catch on dispatch
            dispatch($job);
        } catch (Exception $e) {
            $this->logWarning("Queue Service DOWN. Executing Job Synchronously.");
            // Fallback: Dispatch Now (Sync)
            try {
                dispatch_sync($job);
            } catch (Exception $syncError) {
                $this->logCritical("Sync Job Execution Failed", $syncError);
                // Last Resort: Log payload to DB/File for manual replay
            }
        }
    }

    /**
     * Disk Space Check
     */
    public function diskSpaceSafeCheck()
    {
        $freeSpace = @disk_free_space(storage_path());
        if ($freeSpace !== false && $freeSpace < 100 * 1024 * 1024) { // < 100MB
            $this->logCritical("DISK SPACE CRITICAL: " . $this->formatBytes($freeSpace));
            // Disable heavy logging or file uploads
            config(['logging.default' => 'errorlog']); // Switch to simple PHP error log
            return false;
        }
        return true;
    }

    // --- Private Helpers ---

    private function shouldSkipPrimary($operation) {
        // Implement Circuit Breaker Logic (e.g. check cache key for failure count)
        // For simplicity now: Always return false (Try primary)
        return false;
    }

    private function handleFailure($operation, Exception $e) {
        Log::error("Operation Failed: {$operation} - " . $e->getMessage());
        // Increment failure counter in cache (if available) for Circuit Breaker
    }

    private function logWarning($message) {
        // Try to log, suppress errors if logging fails (Disk Full scenario)
        try {
            Log::warning($message);
        } catch (Exception $e) {
            // Fallback to PHP error_log (server log)
            error_log("FALLBACK LOG (WARNING): {$message}");
        }
    }

    private function logCritical($message, Exception $e = null) {
        $msg = $message . ($e ? " | " . $e->getMessage() : "");
        try {
            Log::critical($msg);
        } catch (Exception $logError) {
            error_log("FALLBACK LOG (CRITICAL): {$msg}");
        }
    }

    private function formatBytes($bytes, $precision = 2) { 
        $units = array('B', 'KB', 'MB', 'GB', 'TB'); 
        $bytes = max($bytes, 0); 
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
        $pow = min($pow, count($units) - 1); 
        $bytes /= pow(1024, $pow); 
        return round($bytes, $precision) . ' ' . $units[$pow]; 
    }
}
