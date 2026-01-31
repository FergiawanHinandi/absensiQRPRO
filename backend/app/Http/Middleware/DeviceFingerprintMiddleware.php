<?php

namespace App\Http\Middleware;

use App\Services\Security\DeviceFingerprintService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * DeviceFingerprintMiddleware
 *
 * Attaches device fingerprint to request for:
 * - Security logging
 * - Anomaly detection
 * - Rate limiting
 *
 * This middleware should be applied to security-sensitive routes.
 */
class DeviceFingerprintMiddleware
{
    public function __construct(
        private DeviceFingerprintService $fingerprintService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Generate device fingerprint
        $fingerprint = $this->fingerprintService->generate(
            $request,
            $request->user()?->id,
            $request->input('device_id') ?? $request->header('X-Device-ID')
        );

        // Store fingerprint in request attributes for later use
        $request->attributes->set('device_fingerprint', $fingerprint);

        // Record usage if user is authenticated
        if ($fingerprint->userId) {
            $this->fingerprintService->recordUsage($fingerprint, $fingerprint->userId);

            // Check for anomalies
            $anomalyResult = $this->fingerprintService->detectAnomalies(
                $fingerprint,
                $fingerprint->userId
            );

            // Store anomaly result in request
            $request->attributes->set('device_anomalies', $anomalyResult);

            // Log high-risk anomalies
            if ($anomalyResult->hasAnomalies) {
                $this->logAnomalies($request, $fingerprint, $anomalyResult);
            }

            // Block if risk is too high (optional - can be enabled per route)
            if ($request->attributes->get('enforce_device_security', false)) {
                if ($anomalyResult->shouldBlock()) {
                    return $this->blockResponse($anomalyResult);
                }
            }
        }

        $response = $next($request);

        // Add fingerprint header for debugging (only first 16 chars)
        if (config('app.debug')) {
            $response->headers->set(
                'X-Device-Fingerprint',
                $fingerprint->getShortFingerprint()
            );
        }

        return $response;
    }

    /**
     * Log detected anomalies
     */
    private function logAnomalies(
        Request $request,
        $fingerprint,
        $anomalyResult
    ): void {
        $context = [
            'event' => 'device.anomaly_detected',
            'fingerprint' => $fingerprint->getShortFingerprint(),
            'user_id' => $fingerprint->userId,
            'risk_score' => $anomalyResult->riskScore,
            'recommendation' => $anomalyResult->recommendation,
            'anomalies' => $anomalyResult->anomalies,
            'path' => $request->path(),
            'method' => $request->method(),
        ];

        $logLevel = match (true) {
            $anomalyResult->riskScore >= 80 => 'critical',
            $anomalyResult->riskScore >= 50 => 'warning',
            default => 'info',
        };

        Log::channel('security_json')->{$logLevel}(
            'Device Anomaly Detected',
            $context
        );
    }

    /**
     * Return blocked response for high-risk requests
     */
    private function blockResponse($anomalyResult): Response
    {
        return response()->json([
            'success' => false,
            'message' => 'Aktivitas mencurigakan terdeteksi. Silakan hubungi administrator.',
            'error_code' => 'DEVICE_SECURITY_BLOCK',
        ], 403);
    }
}
