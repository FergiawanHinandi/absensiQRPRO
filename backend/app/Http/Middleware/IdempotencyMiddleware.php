<?php

namespace App\Http\Middleware;

use App\Logging\LogContext;
use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * IdempotencyMiddleware
 *
 * Prevents replay attacks and duplicate submissions using idempotency keys.
 *
 * HOW IT WORKS:
 * 1. Client MUST send X-Idempotency-Key header (UUID v4)
 * 2. Middleware checks if key exists in database (user_id + endpoint scoped)
 * 3. If exists and not expired: return 409 Conflict (duplicate submission)
 * 4. If not exists: process request normally
 * 5. After successful response: store key in database with TTL
 *
 * SECURITY BENEFITS:
 * - Prevents replay attacks (same key cannot be reused)
 * - Prevents double submissions (network retry safety)
 * - Enables offline sync with pre-generated keys
 * - Works across distributed servers (database-backed)
 * - Server time is source of truth (client timestamp ignored)
 *
 * USAGE:
 * Route::post('/attendance/scan')->middleware('idempotency:2');
 *                                                         ^^
 *                                                    TTL in minutes (default: 2)
 *
 * CLIENT REQUIREMENTS:
 * - MUST send X-Idempotency-Key header with UUID v4
 * - MUST use same key for retries of the same logical request
 * - MUST generate new key for new logical requests
 *
 * @author Security Team
 * @version 2.0.0
 */
class IdempotencyMiddleware
{
    /**
     * Default TTL for idempotency keys (minutes)
     * Short TTL (2 min) for attendance to allow quick retry if needed
     */
    private const DEFAULT_TTL = 2;

    /**
     * Header name for idempotency key
     */
    private const HEADER_NAME = 'X-Idempotency-Key';

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  int|null  $ttlMinutes  TTL for the idempotency key in minutes
     * @param  bool  $required  Whether the idempotency key is required (default: true)
     */
    public function handle(Request $request, Closure $next, ?int $ttlMinutes = null, bool $required = true): Response
    {
        // Only apply to mutating requests (POST, PUT, PATCH, DELETE)
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        // Get idempotency key from header
        $idempotencyKey = $request->header(self::HEADER_NAME);

        // If no key provided
        if (empty($idempotencyKey)) {
            // Log the attempt
            $this->logMissingKey($request);

            // For attendance endpoints, key is REQUIRED
            if ($required) {
                return $this->errorResponse(
                    'Header X-Idempotency-Key wajib untuk endpoint ini.',
                    400,
                    'MISSING_IDEMPOTENCY_KEY'
                );
            }

            return $next($request);
        }

        // Validate key format (must be UUID)
        if (!$this->isValidUuid($idempotencyKey)) {
            $this->logInvalidKey($request, $idempotencyKey);

            return $this->errorResponse(
                'Format X-Idempotency-Key tidak valid. Harus UUID v4.',
                400,
                'INVALID_IDEMPOTENCY_KEY'
            );
        }

        // Check if key already exists (scoped to user + endpoint)
        $existingKey = IdempotencyKey::findExisting(
            $idempotencyKey,
            $request->user()->id,
            $request->path()
        );

        if ($existingKey) {
            // DUPLICATE DETECTED - Return 409 Conflict
            $this->logDuplicateAttempt($request, $idempotencyKey, $existingKey);

            return $this->conflictResponse($idempotencyKey, $existingKey);
        }

        // Process request normally (server time is source of truth)
        $response = $next($request);

        // Store idempotency key after successful response
        if ($response instanceof JsonResponse && $response->isSuccessful()) {
            $this->storeIdempotencyKey(
                $request,
                $idempotencyKey,
                $response,
                $ttlMinutes ?? self::DEFAULT_TTL
            );
        }

        // Add idempotency tracking header to response
        $response->headers->set('X-Idempotency-Key', $idempotencyKey);

        return $response;
    }

    /**
     * Validate UUID format (v4).
     */
    private function isValidUuid(string $uuid): bool
    {
        $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        return preg_match($pattern, $uuid) === 1;
    }

    /**
     * Return 409 Conflict response for duplicate submission.
     */
    private function conflictResponse(string $key, IdempotencyKey $existingKey): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Permintaan duplikat terdeteksi. Request ini sudah diproses sebelumnya.',
            'code' => 'DUPLICATE_SUBMISSION',
            'data' => [
                'idempotency_key' => $key,
                'original_timestamp' => $existingKey->created_at->toIso8601String(),
                'original_status' => $existingKey->response_status,
            ],
        ], 409)
            ->header('X-Duplicate-Request', 'true')
            ->header('X-Original-Timestamp', $existingKey->created_at->toIso8601String());
    }

    /**
     * Log missing idempotency key.
     */
    private function logMissingKey(Request $request): void
    {
        Log::channel('attendance_security')->warning('Request without idempotency key', [
            ...LogContext::getCorrelationContext(),
            'endpoint' => $request->path(),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 100),
            'severity' => 'MEDIUM',
        ]);
    }

    /**
     * Log invalid idempotency key format.
     */
    private function logInvalidKey(Request $request, string $key): void
    {
        Log::channel('attendance_security')->warning('Invalid idempotency key format', [
            ...LogContext::getCorrelationContext(),
            'key_snippet' => substr($key, 0, 20) . '...',
            'endpoint' => $request->path(),
            'user_id' => $request->user()?->id,
            'ip' => $request->ip(),
            'severity' => 'MEDIUM',
        ]);
    }

    /**
     * Log duplicate submission attempt.
     */
    private function logDuplicateAttempt(Request $request, string $key, IdempotencyKey $existingKey): void
    {
        Log::channel('attendance_security')->alert('DUPLICATE SUBMISSION DETECTED', [
            ...LogContext::getCorrelationContext(),
            'type' => 'duplicate_submission',
            'idempotency_key' => $key,
            'endpoint' => $request->path(),
            'user_id' => $request->user()->id,
            'original_created_at' => $existingKey->created_at->toIso8601String(),
            'time_since_original_seconds' => now()->diffInSeconds($existingKey->created_at),
            'ip' => $request->ip(),
            'original_ip' => $existingKey->ip_address,
            'same_ip' => $request->ip() === $existingKey->ip_address,
            'device_id' => $request->header('X-Device-ID'),
            'original_device_id' => $existingKey->device_id,
            'user_agent' => substr($request->userAgent() ?? '', 0, 100),
            'severity' => 'HIGH',
        ]);
    }

    /**
     * Store idempotency key with response data.
     */
    private function storeIdempotencyKey(
        Request $request,
        string $key,
        JsonResponse $response,
        int $ttlMinutes
    ): void {
        try {
            $responseData = json_decode($response->getContent(), true) ?? [];

            IdempotencyKey::store(
                key: $key,
                userId: $request->user()->id,
                endpoint: $request->path(),
                responseData: $responseData,
                responseStatus: $response->getStatusCode(),
                ttlMinutes: $ttlMinutes,
                metadata: [
                    'http_method' => $request->method(),
                    'ip_address' => $request->ip(),
                    'user_agent' => substr($request->userAgent() ?? '', 0, 255),
                    'device_id' => $request->header('X-Device-ID'),
                    'request_id' => LogContext::get('request_id'),
                ]
            );

            Log::channel('attendance_security')->debug('Idempotency key stored', [
                ...LogContext::getCorrelationContext(),
                'key' => $key,
                'user_id' => $request->user()->id,
                'endpoint' => $request->path(),
                'ttl_minutes' => $ttlMinutes,
            ]);
        } catch (\Exception $e) {
            // Don't fail the request if key storage fails
            Log::channel('attendance_security')->error('Failed to store idempotency key', [
                ...LogContext::getCorrelationContext(),
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return error response.
     */
    private function errorResponse(string $message, int $status, string $code): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
        ], $status);
    }
}
