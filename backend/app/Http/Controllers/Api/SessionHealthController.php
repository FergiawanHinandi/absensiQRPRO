<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Session\SessionFallbackManager;
use Illuminate\Http\JsonResponse;

/**
 * Session Health Controller
 * 
 * Provides health check endpoints for session storage monitoring.
 * Used by monitoring systems to track Redis availability and fallback status.
 * 
 * @author Redis HA Team
 * @version 1.0.0
 */
class SessionHealthController extends Controller
{
    private $fallbackManager;

    public function __construct(SessionFallbackManager $fallbackManager)
    {
        $this->fallbackManager = $fallbackManager;
    }

    /**
     * Get session storage health status.
     *
     * @return JsonResponse
     */
    public function status(): JsonResponse
    {
        $status = $this->fallbackManager->getStorageStatus();
        
        return response()->json([
            'status' => true,
            'data' => $status,
        ]);
    }

    /**
     * Get session storage health metrics (Prometheus format).
     *
     * @return JsonResponse
     */
    public function metrics(): JsonResponse
    {
        $metrics = $this->fallbackManager->getHealthMetrics();
        
        return response()->json([
            'status' => true,
            'data' => $metrics,
        ]);
    }

    /**
     * Force a health check and return the result.
     *
     * @return JsonResponse
     */
    public function check(): JsonResponse
    {
        $isHealthy = $this->fallbackManager->forceHealthCheck();
        
        return response()->json([
            'status' => true,
            'data' => [
                'redis_healthy' => $isHealthy,
                'backend' => $isHealthy ? 'redis' : 'database',
            ],
        ]);
    }
}
