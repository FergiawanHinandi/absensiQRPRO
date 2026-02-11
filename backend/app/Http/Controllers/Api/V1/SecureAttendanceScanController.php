<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\SecureAttendanceScanRequest;
use App\Services\AttendanceCheckInService;
use App\Services\QRSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SecureAttendanceScanController (REFACTORED - Clean Architecture)
 *
 * RESPONSIBILITIES (Controller ONLY):
 * 1. Receive & validate request (via FormRequest)
 * 2. Call single service method
 * 3. Return standardized JSON response
 *
 * ALL BUSINESS LOGIC delegated to:
 * - AttendanceCheckInService: Check-in logic, duplicate prevention, etc.
 * - QRSignatureService: Signature verification
 *
 * SECURITY FEATURES (handled by services):
 * - HMAC-SHA256 signature verification
 * - 10-second expiration window
 * - Nonce-based replay prevention
 * - Comprehensive audit logging
 */
class SecureAttendanceScanController extends Controller
{
    public function __construct(
        private AttendanceCheckInService $checkInService,
        private QRSignatureService $signatureService
    ) {}

    /**
     * Process secure QR code scan for attendance
     *
     * @param SecureAttendanceScanRequest $request Validated & authorized request
     * @return JsonResponse
     */
    public function scan(SecureAttendanceScanRequest $request): JsonResponse
    {
        try {
            // 1. Get validated data with defaults
            $data = $request->validatedWithDefaults();
            $qrPayload = $data['qr_payload'];

            // 2. Verify signature via service (throws on failure)
            $verificationResult = $this->signatureService->verifyPayload($qrPayload);

            // 3. Build token for service layer
            $qrToken = $this->buildQrToken($verificationResult, $request->user()->school_id);

            // 4. Delegate ALL logic to service
            $result = $this->checkInService->teacherCheckIn(
                teacher: $request->user(),
                qrToken: $qrToken,
                data: [
                    'lat' => $data['lat'] ?? null,
                    'lng' => $data['lng'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'request_id' => $data['request_id'],
                ],
                request: $request
            );

            // 5. Log success
            $this->logSuccess($request->user()->id, $verificationResult['student_id']);

            // 6. Return standardized response
            return $this->successResponse($result);

        } catch (AttendanceException $e) {
            return $this->errorResponse($e->getMessage(), 400, $this->mapErrorCode($e));

        } catch (\Exception $e) {
            $this->logError($request->user(), $e);

            return $this->errorResponse(
                app()->isProduction() ? 'Terjadi kesalahan saat memproses absensi.' : $e->getMessage(),
                500,
                'INTERNAL_ERROR'
            );
        }
    }

    /**
     * Scan using encoded QR string (base64 JSON)
     *
     * Alternative endpoint for mobile apps that scan raw QR code content.
     */
    public function scanEncoded(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_encoded' => 'required|string',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'device_id' => 'nullable|string|max:255',
            'request_id' => 'nullable|uuid',
        ]);

        $teacher = $request->user();

        // Authorization check
        if (!in_array($teacher->role_type, ['teacher', 'homeroom_teacher'])) {
            return $this->errorResponse('Unauthorized. Hanya guru yang dapat melakukan scan.', 403, 'UNAUTHORIZED');
        }

        try {
            // 1. Verify encoded QR via service
            $verificationResult = $this->signatureService->verifyEncodedPayload($validated['qr_encoded']);

            // 2. Build token
            $qrToken = $this->buildQrToken($verificationResult, $teacher->school_id);

            // 3. Delegate to service
            $result = $this->checkInService->teacherCheckIn(
                teacher: $teacher,
                qrToken: $qrToken,
                data: [
                    'lat' => $validated['lat'] ?? null,
                    'lng' => $validated['lng'] ?? null,
                    'device_id' => $validated['device_id'] ?? null,
                    'request_id' => $validated['request_id'] ?? (string) Str::uuid(),
                ],
                request: $request
            );

            return $this->successResponse($result);

        } catch (\Exception $e) {
            Log::channel('attendance_security')->warning('Encoded QR verification failed', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
                'payload_snippet' => substr($validated['qr_encoded'], 0, 20) . '...',
            ]);

            return $this->errorResponse($e->getMessage(), 400, 'QR_VERIFICATION_FAILED');
        }
    }

    /**
     * Generate a new signed QR for a student
     */
    public function generateQR(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:users,id',
        ]);

        $user = $request->user();

        // Authorization
        if (!in_array($user->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin'])) {
            return $this->errorResponse('Unauthorized.', 403, 'UNAUTHORIZED');
        }

        // Fetch student with school validation
        $student = \App\Models\User::where('id', $validated['student_id'])
            ->where('role_type', 'student')
            ->where('school_id', $user->school_id)
            ->first();

        if (!$student) {
            return $this->errorResponse('Siswa tidak ditemukan.', 404, 'STUDENT_NOT_FOUND');
        }

        // Generate via service
        $qrData = $this->signatureService->createForStudent($student);

        Log::channel('attendance_security')->info('QR Generated', [
            'generator_id' => $user->id,
            'student_id' => $student->id,
            'expires_at' => $qrData['expires_at'],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'QR Code berhasil dibuat.',
            'data' => [
                'qr_payload' => $qrData['payload'],
                'qr_encoded' => $qrData['encoded'],
                'expires_at' => $qrData['expires_at'],
                'valid_for_seconds' => $qrData['valid_for_seconds'],
            ],
        ]);
    }

    /**
     * Build QR token from verification result
     */
    private function buildQrToken(array $result, int $schoolId): string
    {
        $payload = [
            'sid' => $result['student_id'],
            'sch' => $schoolId,
            'iat' => is_numeric($result['generated_at'])
                ? $result['generated_at']
                : \Carbon\Carbon::parse($result['generated_at'])->timestamp,
            'typ' => 'secure_scan',
            'n' => Str::random(16),
            'v' => 1,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        return $encoded . '.' . $signature;
    }

    /**
     * Success response
     */
    private function successResponse($result): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $result->message,
            'data' => [
                'attendance' => $result->attendance,
                'status' => $result->status,
                'is_retry' => $result->isIdempotentRetry,
            ],
        ], $result->isIdempotentRetry ? 200 : 201);
    }

    /**
     * Error response
     */
    private function errorResponse(string $message, int $status, string $code = 'ERROR'): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'code' => $code,
            'data' => null,
        ], $status);
    }

    /**
     * Map exception to error code
     */
    private function mapErrorCode(AttendanceException $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'sudah dicatat') => 'ALREADY_CHECKED_IN',
            str_contains($message, 'tidak valid') => 'INVALID_QR',
            str_contains($message, 'kadaluarsa') => 'QR_EXPIRED',
            str_contains($message, 'jadwal') => 'NO_ACTIVE_SCHEDULE',
            str_contains($message, 'radius') => 'OUTSIDE_GEOFENCE',
            str_contains($message, 'waktu') => 'OUTSIDE_TIME_WINDOW',
            str_contains($message, 'kelas') => 'STUDENT_NOT_IN_CLASS',
            str_contains($message, 'replay') => 'QR_REPLAY_DETECTED',
            default => 'ATTENDANCE_ERROR',
        };
    }

    /**
     * Log successful scan
     */
    private function logSuccess(int $teacherId, int $studentId): void
    {
        Log::channel('attendance_security')->info('Secure attendance recorded', [
            'teacher_id' => $teacherId,
            'student_id' => $studentId,
            'ip_address' => request()->ip(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log error
     */
    private function logError($teacher, \Exception $e): void
    {
        Log::channel('attendance_security')->error('Secure attendance error', [
            'teacher_id' => $teacher?->id,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'ip_address' => request()->ip(),
        ]);
    }
}
