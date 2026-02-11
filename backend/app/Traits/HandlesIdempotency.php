<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;

trait HandlesIdempotency
{
    /**
     * Execute a callback with idempotency protection.
     *
     * @param Request $request
     * @param callable $callback Logic to execute if not duplicate
     * @param int $ttlSeconds Time to keep the result in cache (default 1 day)
     * @return JsonResponse
     */
    protected function withIdempotency(Request $request, callable $callback, int $ttlSeconds = 86400): JsonResponse
    {
        // 1. Get Idempotency Key (header preferred)
        $key = $request->header('Idempotency-Key') ?? $request->input('idempotency_key');

        if (!$key) {
            // If no key provided, just execute normally (or strict error if mandated)
            return $callback();
        }

        $cacheKey = 'idempotency_' . $key;

        // 2. Check if result already cached (Already Processed)
        if (Cache::has($cacheKey)) {
            $cachedResponse = Cache::get($cacheKey);
            return response()->json($cachedResponse['data'], $cachedResponse['status'], $cachedResponse['headers'] ?? []);
        }

        // 3. Coordinate Lock (Processing in Progress)
        // Prevent race condition if two requests with same key arrive simultaneously
        $lock = Cache::lock('lock_' . $cacheKey, 10); // 10 seconds lock

        if (!$lock->get()) {
            return response()->json([
                'status' => 'error',
                'code' => 'PROCESSING',
                'message' => 'Request is currently being processed.'
            ], 409);
        }

        try {
            // 4. Execute Logic
            /** @var JsonResponse $response */
            $response = $callback();

            // 5. Cache the Result ONLY if success (2xx)
            // Error responses (4xx, 5xx) might be retried differently? 
            // Usually idempotency caches success final states.
            if ($response->status() >= 200 && $response->status() < 300) {
                Cache::put($cacheKey, [
                    'status' => $response->status(),
                    'data' => $response->getData(true), // Save as array
                    'headers' => [], // Optional: save specific headers
                ], $ttlSeconds);
            }

            return $response;

        } finally {
            $lock->release();
        }
    }
}
