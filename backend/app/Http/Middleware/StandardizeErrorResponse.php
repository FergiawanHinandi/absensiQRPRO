<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StandardizeErrorResponse
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        $response = $next($request);

        // Check for specific error status codes
        if (in_array($response->getStatusCode(), [401, 403, 404])) {

            // SECURITY: Mitigate Timing Attacks
            // Pad response time to a minimum threshold (e.g., 300ms - 500ms range)
            // to make it harder to distinguish between "User not found" (fast) vs "User found but wrong password" (slow)
            // or "Resource not found" vs "Resource exists but forbidden"

            $elapsed = (microtime(true) - $startTime) * 1000; // ms
            $targetDuration = rand(200, 400); // Randomize slightly to confuse statistical analysis further

            if ($elapsed < $targetDuration) {
                usleep(($targetDuration - $elapsed) * 1000);
            }

            // Standardize generic error format if not already valid JSON
            // (Only for these sensitive codes to ensure uniformity)
            // Note: Laravel's exception handler usually handles JSON, but this adds a layer of safety
            // and ensures 403 vs 404 look identical in structure.

            if ($response->getStatusCode() === 403 || $response->getStatusCode() === 404) {
                // You might want to override content here to look exactly the same if strict masking is needed.
                // For now, we rely on the timing pad.
            }
        }

        return $response;
    }
}
