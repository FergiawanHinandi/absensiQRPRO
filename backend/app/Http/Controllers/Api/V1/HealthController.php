<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SafeRedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Health Check Controller
 * 
 * Provides system health status including circuit breaker states
 */
class HealthController extends Controller
{
    public function __construct(
        private SafeRedisService $redis
    ) {}
    
    /**
     * Get system health status (alias for index)
     * 
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        return $this->index();
    }
    
    /**
     * Get system health status
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $health = [
            'status' => 'healthy',
            'timestamp' => now()->toIso8601String(),
            'services' => [],
        ];
        
        // Check database
        try {
            DB::connection()->getPdo();
            $health['services']['database'] = [
                'status' => 'up',
                'connection' => DB::connection()->getName(),
            ];
        } catch (\Exception $e) {
            $health['status'] = 'degraded';
            $health['services']['database'] = [
                'status' => 'down',
                'error' => $e->getMessage(),
            ];
        }
        
        // Check Redis with circuit breaker status
        $circuitStatus = $this->redis->getCircuitStatus();
        $redisAvailable = $circuitStatus['is_available'];
        
        // Add redis_status at top level for easy monitoring
        $health['redis_status'] = strtoupper($circuitStatus['state']); // OPEN/CLOSED/HALF_OPEN
        
        $health['services']['redis'] = [
            'status' => $redisAvailable ? 'up' : 'down',
            'circuit_breaker' => $circuitStatus,
        ];
        
        if (!$redisAvailable) {
            $health['status'] = 'degraded';
            $health['message'] = 'Redis is unavailable, using fallback mechanisms';
        }
        
        // Overall health status code
        $statusCode = match($health['status']) {
            'healthy' => 200,
            'degraded' => 200, // Still operational with fallbacks
            'unhealthy' => 503,
            default => 500,
        };
        
        return response()->json($health, $statusCode);
    }
    
    /**
     * Get detailed circuit breaker status
     * 
     * @return JsonResponse
     */
    public function circuitBreaker(): JsonResponse
    {
        $status = $this->redis->getCircuitStatus();
        
        return response()->json([
            'redis_circuit_breaker' => $status,
            'recommendations' => $this->getRecommendations($status),
        ]);
    }
    
    /**
     * Redis-specific health check endpoint
     * 
     * Tests Redis connectivity and returns appropriate status codes:
     * - 200: Redis is healthy and connected
     * - 503: Redis is unavailable (circuit breaker open or connection failed)
     * 
     * @return JsonResponse
     */
    public function redis(): JsonResponse
    {
        try {
            $circuitStatus = $this->redis->getCircuitStatus();
            
            // Check if circuit breaker is open (Redis unavailable)
            if (isset($circuitStatus['state']) && $circuitStatus['state'] === 'open') {
                return response()->json([
                    'status' => 'unhealthy',
                    'redis' => 'disconnected',
                    'circuit_breaker' => [
                        'state' => $circuitStatus['state'],
                        'failure_count' => $circuitStatus['failure_count'] ?? 0,
                        'time_since_last_failure' => $circuitStatus['time_since_last_failure'] ?? null,
                    ],
                    'message' => 'Redis circuit breaker is OPEN. System is using fallback mechanisms.',
                    'error' => 'Circuit breaker triggered due to repeated failures',
                ], 503);
            }
            
            // Test actual Redis connection with a simple operation
            $testKey = 'health_check_' . time();
            $testValue = 'ok';
            
            // Try to write and read from Redis
            $setResult = $this->redis->set($testKey, $testValue, 10);
            
            // If set failed, Redis is unavailable
            if ($setResult === false) {
                throw new \Exception('Redis SET operation failed');
            }
            
            $result = $this->redis->get($testKey);
            
            // Verify the operation succeeded
            if ($result !== $testValue) {
                throw new \Exception('Redis read/write verification failed');
            }
            
            // Clean up test key
            $this->redis->delete($testKey);
            
            return response()->json([
                'status' => 'healthy',
                'redis' => 'connected',
                'circuit_breaker' => [
                    'state' => $circuitStatus['state'] ?? 'closed',
                    'failure_count' => $circuitStatus['failure_count'] ?? 0,
                ],
                'message' => 'Redis is operational',
                'timestamp' => now()->toIso8601String(),
            ], 200);
            
        } catch (\Exception $e) {
            // Log the error for monitoring
            \Log::warning('Redis health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'unhealthy',
                'redis' => 'disconnected',
                'error' => $e->getMessage(),
                'message' => 'Redis connection test failed',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
    
    /**
     * Database health check endpoint
     * 
     * Tests database connectivity and basic query operations
     * 
     * @return JsonResponse
     */
    public function database(): JsonResponse
    {
        try {
            $startTime = microtime(true);
            
            // Test basic connection
            $pdo = DB::connection()->getPdo();
            $connectionTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Test read query
            $readStart = microtime(true);
            DB::select('SELECT 1 as test');
            $readTime = round((microtime(true) - $readStart) * 1000, 2);
            
            // Test write query (using a transaction to avoid side effects)
            $writeStart = microtime(true);
            DB::beginTransaction();
            DB::statement('SELECT 1 FOR UPDATE');
            DB::rollBack();
            $writeTime = round((microtime(true) - $writeStart) * 1000, 2);
            
            // Get connection stats
            $driver = DB::connection()->getDriverName();
            $connectionName = DB::connection()->getName();
            
            // Check replication status if applicable (PostgreSQL)
            $replicationStatus = null;
            if ($driver === 'pgsql') {
                try {
                    $replicationStatus = DB::select("SELECT pg_is_in_recovery() as is_replica")[0]->is_replica ?? false;
                } catch (\Exception $e) {
                    // Ignore if query fails
                }
            }
            
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            
            return response()->json([
                'status' => 'healthy',
                'database' => 'connected',
                'connection' => $connectionName,
                'driver' => $driver,
                'response_time_ms' => $totalTime,
                'metrics' => [
                    'connection_time_ms' => $connectionTime,
                    'read_time_ms' => $readTime,
                    'write_time_ms' => $writeTime,
                ],
                'replication' => [
                    'is_replica' => $replicationStatus,
                ],
                'timestamp' => now()->toIso8601String(),
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Database health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'unhealthy',
                'database' => 'disconnected',
                'error' => $e->getMessage(),
                'message' => 'Database connection test failed',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
    
    /**
     * Storage health check endpoint
     * 
     * Tests storage connectivity and disk space
     * 
     * @return JsonResponse
     */
    public function storage(): JsonResponse
    {
        try {
            $startTime = microtime(true);
            
            // Test local disk storage
            $storagePath = storage_path();
            $diskTotal = disk_total_space($storagePath);
            $diskFree = disk_free_space($storagePath);
            $diskUsed = $diskTotal - $diskFree;
            $diskUsedPercent = round(($diskUsed / $diskTotal) * 100, 2);
            
            // Test write/read operations
            $testFile = 'health_check_' . time() . '.tmp';
            $testContent = 'health_check_test';
            
            $writeStart = microtime(true);
            \Storage::disk('local')->put($testFile, $testContent);
            $writeTime = round((microtime(true) - $writeStart) * 1000, 2);
            
            $readStart = microtime(true);
            $retrieved = \Storage::disk('local')->get($testFile);
            $readTime = round((microtime(true) - $readStart) * 1000, 2);
            
            // Cleanup
            \Storage::disk('local')->delete($testFile);
            
            // Verify operation
            if ($retrieved !== $testContent) {
                throw new \Exception('Storage read/write verification failed');
            }
            
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Determine health status based on disk usage
            $status = 'healthy';
            if ($diskUsedPercent > 90) {
                $status = 'critical';
            } elseif ($diskUsedPercent > 80) {
                $status = 'warning';
            }
            
            return response()->json([
                'status' => $status,
                'storage' => 'connected',
                'disk' => config('filesystems.default'),
                'response_time_ms' => $totalTime,
                'metrics' => [
                    'write_time_ms' => $writeTime,
                    'read_time_ms' => $readTime,
                ],
                'disk_space' => [
                    'total_gb' => round($diskTotal / 1024 / 1024 / 1024, 2),
                    'free_gb' => round($diskFree / 1024 / 1024 / 1024, 2),
                    'used_gb' => round($diskUsed / 1024 / 1024 / 1024, 2),
                    'used_percent' => $diskUsedPercent,
                ],
                'timestamp' => now()->toIso8601String(),
            ], $status === 'critical' ? 503 : 200);
            
        } catch (\Exception $e) {
            \Log::error('Storage health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'unhealthy',
                'storage' => 'disconnected',
                'error' => $e->getMessage(),
                'message' => 'Storage connectivity test failed',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }
    
    /**
     * Queue health check endpoint
     * 
     * Checks queue worker status and monitors queue health
     * 
     * @return JsonResponse
     */
    public function queue(): JsonResponse
    {
        try {
            $startTime = microtime(true);
            
            // Get pending jobs count
            $pendingJobs = DB::table('jobs')->count();
            
            // Get failed jobs count
            $failedJobs = DB::table('failed_jobs')->count();
            
            // Get failed jobs in last hour
            $recentFailedJobs = DB::table('failed_jobs')
                ->where('failed_at', '>', now()->subHour())
                ->count();
            
            // Get oldest pending job
            $oldestJob = DB::table('jobs')
                ->orderBy('created_at', 'asc')
                ->first();
            
            $oldestJobAge = $oldestJob 
                ? now()->diffInSeconds($oldestJob->created_at)
                : 0;
            
            // Check if workers are active (estimate from recent job processing)
            $recentlyProcessedJobs = DB::table('jobs')
                ->where('created_at', '>', now()->subMinutes(5))
                ->count();
            
            $workersActive = $recentlyProcessedJobs > 0 || $pendingJobs === 0;
            
            $totalTime = round((microtime(true) - $startTime) * 1000, 2);
            
            // Determine health status
            $status = 'healthy';
            $warnings = [];
            
            if ($recentFailedJobs > 50) {
                $status = 'critical';
                $warnings[] = 'High number of failed jobs in the last hour';
            } elseif ($recentFailedJobs > 10) {
                $status = 'warning';
                $warnings[] = 'Elevated failed job count';
            }
            
            if ($pendingJobs > 1000) {
                $status = 'critical';
                $warnings[] = 'Queue backlog is very high';
            } elseif ($pendingJobs > 500) {
                $status = $status === 'critical' ? 'critical' : 'warning';
                $warnings[] = 'Queue backlog is elevated';
            }
            
            if ($oldestJobAge > 600) {
                $status = 'critical';
                $warnings[] = 'Jobs are waiting too long in queue';
            } elseif ($oldestJobAge > 300) {
                $status = $status === 'critical' ? 'critical' : 'warning';
                $warnings[] = 'Job processing is slower than expected';
            }
            
            if (!$workersActive && $pendingJobs > 0) {
                $status = 'critical';
                $warnings[] = 'No active queue workers detected';
            }
            
            return response()->json([
                'status' => $status,
                'queue' => 'operational',
                'response_time_ms' => $totalTime,
                'metrics' => [
                    'pending_jobs' => $pendingJobs,
                    'failed_jobs' => $failedJobs,
                    'recent_failed_jobs' => $recentFailedJobs,
                    'oldest_job_age_seconds' => $oldestJobAge,
                    'workers_active' => $workersActive,
                ],
                'warnings' => $warnings,
                'timestamp' => now()->toIso8601String(),
            ], $status === 'critical' ? 503 : 200);
            
        } catch (\Exception $e) {
            \Log::error('Queue health check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return response()->json([
                'status' => 'unhealthy',
                'queue' => 'unavailable',
                'error' => $e->getMessage(),
                'message' => 'Queue health check failed',
                'timestamp' => now()->toIso8601String(),
            ], 503);
        }
    }

    /**
     * Get system metrics for observability
     *
     * Provides real-time metrics for database, Redis, queue, and application performance.
     *
     * @return JsonResponse
     */
    public function metrics(): JsonResponse
    {
        $metrics = [
            'timestamp' => now()->toIso8601String(),
            'uptime_seconds' => time() - (int) (defined('LARAVEL_START') ? LARAVEL_START : time()),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
        ];

        // Database metrics
        try {
            $dbStart = microtime(true);
            DB::select('SELECT 1');
            $metrics['database'] = [
                'status' => 'up',
                'response_time_ms' => round((microtime(true) - $dbStart) * 1000, 2),
                'connection' => DB::connection()->getName(),
                'total_connections' => DB::select("SELECT count(*) as count FROM pg_stat_activity WHERE datname = current_database()")[0]->count ?? null,
            ];
        } catch (\Exception $e) {
            $metrics['database'] = [
                'status' => 'down',
                'error' => $e->getMessage(),
            ];
        }

        // Redis metrics
        $circuitStatus = $this->redis->getCircuitStatus();
        $metrics['redis'] = [
            'status' => $circuitStatus['is_available'] ? 'up' : 'down',
            'circuit_breaker_state' => $circuitStatus['state'] ?? 'unknown',
        ];

        // Application metrics
        $metrics['application'] = [
            'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];

        // Queue metrics
        try {
            $metrics['queue'] = [
                'pending_jobs' => DB::table('jobs')->count(),
                'failed_jobs' => DB::table('failed_jobs')->count(),
            ];
        } catch (\Exception $e) {
            $metrics['queue'] = ['status' => 'unavailable'];
        }

        return response()->json($metrics);
    }

    /**
     * Get recommendations based on circuit state
     */
    private function getRecommendations(array $status): array
    {
        $recommendations = [];
        
        if ($status['state'] === 'open') {
            $recommendations[] = 'Redis circuit is OPEN. Check Redis server health.';
            $recommendations[] = 'System is using database fallback for locking.';
            $recommendations[] = sprintf(
                'Circuit will attempt recovery in %d seconds.',
                max(0, $status['retry_timeout'] - ($status['time_since_last_failure'] ?? 0))
            );
        }
        
        if ($status['state'] === 'half_open') {
            $recommendations[] = 'Redis circuit is HALF_OPEN. Testing recovery.';
            $recommendations[] = 'Monitor for successful operations to close circuit.';
        }
        
        if ($status['failure_count'] > 0 && $status['state'] === 'closed') {
            $recommendations[] = sprintf(
                'Warning: %d recent failures detected. Monitor Redis stability.',
                $status['failure_count']
            );
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'All systems operational.';
        }
        
        return $recommendations;
    }
}
