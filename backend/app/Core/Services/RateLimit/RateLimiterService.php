<?php

namespace App\Core\Services\RateLimit;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Core Rate Limiter Service
 *
 * Orchestrates sliding window counter + tenant key builder.
 * Provides attempt(), remaining(), reset(), and status() methods.
 *
 * Spec: critical-rate-limiting / tasks.md Task 4
 */
class RateLimiterService
{
    public function __construct(
        private readonly SlidingWindowCounter $counter,
        private readonly TenantKeyBuilder     $keyBuilder,
        private readonly AdminBypassService   $bypassService,
        private readonly RateLimitLogger      $logger,
    ) {}

    /**
     * Attempt a rate limited action.
     *
     * @return array{allowed: bool, count: int, remaining: int, retry_after: int, key: string}
     */
    public function attempt(Request $request, string $endpointKey): array
    {
        $config = config("rate-limiting.endpoints.{$endpointKey}");

        if (!$config) {
            return $this->allowedResponse(0, 999, 0, 'unknown');
        }

        // Check admin bypass first
        if ($this->bypassService->shouldBypass($request, $endpointKey)) {
            $this->logger->logBypass($request, $endpointKey);
            return $this->allowedResponse(0, $config['max_attempts'], 0, 'bypass');
        }

        // Build tenant-scoped key
        $key = $this->keyBuilder->build($request, $endpointKey);

        // Attempt with sliding window
        $result = $this->counter->attempt(
            $key,
            $config['max_attempts'],
            $config['decay_seconds']
        );

        $remaining  = max(0, $config['max_attempts'] - $result['count']);
        $retryAfter = $result['allowed'] ? 0 : $this->counter->ttl($key);

        if (!$result['allowed'] && ($config['log_violations'] ?? false)) {
            $this->logger->logViolation($request, $endpointKey, $key, $config, $result['count']);
        }

        return [
            'allowed'     => $result['allowed'],
            'count'       => $result['count'],
            'remaining'   => $remaining,
            'limit'       => $config['max_attempts'],
            'retry_after' => $retryAfter,
            'window'      => $config['decay_seconds'],
            'key'         => $key,
            'description' => $config['description'],
        ];
    }

    /** Get remaining attempts without consuming one. */
    public function remaining(Request $request, string $endpointKey): int
    {
        $config = config("rate-limiting.endpoints.{$endpointKey}");
        if (!$config) {
            return 999;
        }

        $key   = $this->keyBuilder->build($request, $endpointKey);
        $count = $this->counter->count($key, $config['decay_seconds']);

        return max(0, $config['max_attempts'] - $count);
    }

    /** Manually reset rate limit for a request. */
    public function reset(Request $request, string $endpointKey): bool
    {
        $key = $this->keyBuilder->build($request, $endpointKey);
        Log::info('RateLimiter: Manual reset', ['key' => $key, 'endpoint' => $endpointKey]);
        return $this->counter->reset($key);
    }

    /** Reset rate limit by Redis key directly. */
    public function resetByKey(string $key): bool
    {
        return $this->counter->reset($key);
    }

    /** Get current status for all endpoints. */
    public function status(Request $request): array
    {
        $status = [];
        foreach (config('rate-limiting.endpoints', []) as $endpointKey => $config) {
            $key   = $this->keyBuilder->build($request, $endpointKey);
            $count = $this->counter->count($key, $config['decay_seconds']);

            $status[$endpointKey] = [
                'limit'       => $config['max_attempts'],
                'consumed'    => $count,
                'remaining'   => max(0, $config['max_attempts'] - $count),
                'window_secs' => $config['decay_seconds'],
                'description' => $config['description'],
            ];
        }
        return $status;
    }

    private function allowedResponse(int $count, int $limit, int $retryAfter, string $key): array
    {
        return [
            'allowed'     => true,
            'count'       => $count,
            'remaining'   => $limit - $count,
            'limit'       => $limit,
            'retry_after' => $retryAfter,
            'window'      => 60,
            'key'         => $key,
            'description' => 'Bypassed',
        ];
    }
}
