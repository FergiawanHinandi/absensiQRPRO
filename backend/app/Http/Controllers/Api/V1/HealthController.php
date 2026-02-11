<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SafeRedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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
