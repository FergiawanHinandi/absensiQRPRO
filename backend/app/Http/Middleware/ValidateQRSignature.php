<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * QR Signature Validation Middleware
 * 
 * SECURITY CHECKS:
 * 1. ✅ Validates HMAC signature
 * 2. ✅ Validates timestamp (60s window)
 * 3. ✅ Validates required fields
 * 4. ✅ Logs invalid attempts
 * 
 * Apply to QR scan endpoints:
 * Route::post('/scan', [AttendanceController::class, 'scan'])
 *     ->middleware(['auth:sanctum', 'throttle:scan', 'qr.validate']);
 */
class ValidateQRSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only validate if qr_payload is present
        if (!$request->has('qr_payload')) {
            return response()->json([
                'success' => false,
                'message' => 'QR payload tidak ditemukan.',
            ], 400);
        }

        $payload = $request->input('qr_payload');

        // Decode JSON if string
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logInvalidAttempt('invalid_json', $request);
                return response()->json([
                    'success' => false,
                    'message' => 'QR payload tidak valid: JSON error.',
                ], 400);
            }
        }

        // 1. VALIDATE STRUCTURE
        if (!isset($payload['signature']) || !isset($payload['data'])) {
            $this->logInvalidAttempt('missing_signature_or_data', $request, $payload);
            return response()->json([
                'success' => false,
                'message' => 'QR Code tidak valid: struktur tidak lengkap.',
            ], 400);
        }

        // 2. VALIDATE REQUIRED FIELDS
        $requiredFields = ['schedule_id', 'school_id', 'expires_at', 'idempotency_key'];
        foreach ($requiredFields as $field) {
            if (!isset($payload['data'][$field])) {
                $this->logInvalidAttempt("missing_field_{$field}", $request, $payload);
                return response()->json([
                    'success' => false,
                    'message' => "QR Code tidak valid: {$field} tidak ditemukan.",
                ], 400);
            }
        }

        // 3. VALIDATE SIGNATURE
        $expectedSignature = hash_hmac(
            'sha256',
            json_encode($payload['data']),
            config('app.key')
        );

        if (!hash_equals($expectedSignature, $payload['signature'])) {
            $this->logInvalidAttempt('signature_mismatch', $request, $payload);
            return response()->json([
                'success' => false,
                'message' => 'QR Code tidak valid: signature tidak cocok.',
            ], 403);
        }

        // 4. VALIDATE TIMESTAMP (60 second window)
        $expiresAt = \Carbon\Carbon::parse($payload['data']['expires_at']);
        $now = \Carbon\Carbon::now();

        if ($now->greaterThan($expiresAt)) {
            $this->logInvalidAttempt('expired_qr', $request, [
                'expires_at' => $expiresAt->toIso8601String(),
                'now' => $now->toIso8601String(),
                'diff_seconds' => $now->diffInSeconds($expiresAt),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'QR Code sudah kadaluarsa. Silakan refresh QR code.',
            ], 410); // 410 Gone
        }

        // 5. VALIDATE TIMESTAMP NOT TOO FAR IN FUTURE (clock manipulation)
        if ($expiresAt->greaterThan($now->addMinutes(2))) {
            $this->logInvalidAttempt('future_timestamp', $request, [
                'expires_at' => $expiresAt->toIso8601String(),
                'now' => \Carbon\Carbon::now()->toIso8601String(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'QR Code tidak valid: timestamp tidak wajar.',
            ], 400);
        }

        // All validations passed - attach decoded payload to request
        $request->merge(['validated_qr_payload' => $payload]);

        return $next($request);
    }

    /**
     * Log invalid QR attempt
     * 
     * @param string $reason
     * @param Request $request
     * @param array $context
     */
    protected function logInvalidAttempt(string $reason, Request $request, array $context = []): void
    {
        Log::channel('security')->warning("invalid_qr_attempt: {$reason}", array_merge($context, [
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toIso8601String(),
        ]));

        // Also log to activity_logs table if user is authenticated
        if ($request->user()) {
            activity()
                ->causedBy($request->user())
                ->withProperties(array_merge($context, ['reason' => $reason]))
                ->log("invalid_qr_attempt: {$reason}");
        }
    }
}
