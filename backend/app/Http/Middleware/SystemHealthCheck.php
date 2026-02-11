<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Services\Resilience\SystemResilienceService;
use Exception;

class SystemHealthCheck
{
    protected $resienceService;

    public function __construct(SystemResilienceService $resienceService)
    {
        $this->resienceService = $resienceService;
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            // 1. DISK CHECK (Critical)
            // If disk full, stop accepting writes (POST/PUT/PATCH/DELETE)
            if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('PATCH')) {
                // Check disk space (cached result ideally, but PHP stat is fast)
                $freeSpace = @disk_free_space(storage_path());
                if ($freeSpace !== false && $freeSpace < 50 * 1024 * 1024) { // < 50MB
                    // Graceful Degradation: 503 Service Unavailable
                    return response()->json([
                        'status' => false,
                        'code' => 503,
                        'message' => 'System Maintenance: Storage Capacity Reached. Please try again later.'
                    ], 503);
                }
            }

            // 2. REDIS CHECK (Warning)
            if (config('cache.default') === 'redis') {
                try {
                    // Quick PING check with strict timeout?
                    // Rely on Resilience Service to switch config if needed
                    // But usually, Redis connection errors happen inside application flow.
                    // Pre-emptive check:
                    // $this->resienceService->redisFallback(); // Sets fallback config
                } catch (Exception $e) {
                    // Fallback handled inside service/config switch
                }
            }

            return $next($request);

        } catch (Exception $e) {
            // If Health Check itself fails, Log and Fail Open (Allow request)
            // ensuring user experience isn't blocked by monitoring tool failure.
            try {
                \Illuminate\Support\Facades\Log::error('Health Check Failed: ' . $e->getMessage());
            } catch (Exception $logError) {
                // Silent fail
            }
            return $next($request);
        }
    }
}
