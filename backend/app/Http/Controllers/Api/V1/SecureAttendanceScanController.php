<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use App\Services\QRSignatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Secure Attendance Scan Controller
 *
 * Handles student attendance check-in with cryptographic signature validation
 * and anti-replay protection using QRSignatureService.
 *
 * SECURITY FEATURES:
 * - HMAC-SHA256 signature verification
 * - 10-second expiration window
 * - Comprehensive security logging
 * - Failed attempt tracking
 *
 * @author Security Team
 * @version 1.0.0
 */
class SecureAttendanceScanController extends Controller
{
    public function __construct(
        private AttendanceService $attendanceService,
        private QRSignatureService $signatureService
    ) {}

    /**
     * Process secure QR code scan for attendance
     *
     * This endpoint validates:
     * 1. QR signature authenticity (HMAC-SHA256)
     * 2. QR expiration (10 seconds default)
     * 3. Student exists and is active
     * 4. No duplicate attendance for today
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @example Request payload:
     * {
     *     "qr_payload": {
     *         "student_id": 123,
     *         "nisn": "0012345678",
     *         "generated_at": 1706688000,
     *         "signature": "a1b2c3d4..."
     *     },
     *     "lat": -6.2088,
     *     "lng": 106.8456,
     *     "device_id": "device-uuid-123"
     * }
     */
    public function scan(Request $request): JsonResponse
    {
        // Validate request structure
        $validated = $request->validate([
            'qr_payload' => 'required|array',
            'qr_payload.student_id' => 'required|integer',
            'qr_payload.nisn' => 'required|string',
            'qr_payload.generated_at' => 'required|integer',
            'qr_payload.signature' => 'required|string',
            'lat' => 'nullable|numeric|between:-90,90',
            'lng' => 'nullable|numeric|between:-180,180',
            'device_id' => 'nullable|string|max:255',
            'request_id' => 'nullable|uuid',
        ]);

        $teacher = $request->user();
        $qrPayload = $validated['qr_payload'];

        // STEP 1: Verify teacher role
        if (!$this->isTeacher($teacher)) {
            $this->logSecurityEvent('unauthorized_role', [
                'user_id' => $teacher->id,
                'role' => $teacher->role_type,
                'action' => 'scan_attempt',
            ]);

            return response()->json([
                'status' => 'fail',
                'message' => 'Unauthorized. Hanya guru yang dapat melakukan scan.',
            ], 403);
        }

        // STEP 2: Verify QR signature and expiration
        try {
            $verificationResult = $this->signatureService->verifyPayload($qrPayload);
        } catch (\Exception $e) {
            $this->logSecurityEvent('signature_verification_failed', [
                'teacher_id' => $teacher->id,
                'student_id' => $qrPayload['student_id'] ?? 'unknown',
                'nisn' => $this->maskNisn($qrPayload['nisn'] ?? ''),
                'error' => $e->getMessage(),
                'generated_at' => $qrPayload['generated_at'] ?? null,
            ]);

            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], 400);
        }

        // STEP 3: Process attendance in transaction
        try {
            return DB::transaction(function () use ($teacher, $verificationResult, $validated) {
                // Verify student exists and belongs to same school
                $student = \App\Models\User::where('id', $verificationResult['student_id'])
                    ->where('role_type', 'student')
                    ->where('school_id', $teacher->school_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$student) {
                    $this->logSecurityEvent('student_not_found', [
                        'teacher_id' => $teacher->id,
                        'student_id' => $verificationResult['student_id'],
                        'nisn' => $this->maskNisn($verificationResult['nisn']),
                        'school_id' => $teacher->school_id,
                    ]);

                    throw new \Exception('Siswa tidak ditemukan atau tidak aktif.');
                }

                // Check for existing attendance today
                $existingAttendance = \App\Models\Attendance::where('student_id', $student->id)
                    ->whereDate('attendance_date', today())
                    ->lockForUpdate()
                    ->first();

                if ($existingAttendance) {
                    $this->logSecurityEvent('duplicate_attendance_attempt', [
                        'teacher_id' => $teacher->id,
                        'student_id' => $student->id,
                        'existing_attendance_id' => $existingAttendance->id,
                    ]);

                    throw new \Exception('Siswa sudah melakukan absensi hari ini.');
                }

                // Delegate to attendance service for actual recording
                $result = $this->attendanceService->recordByTeacherScan(
                    $teacher,
                    $this->buildQrToken($student, $verificationResult),
                    $validated['lat'] ?? null,
                    $validated['lng'] ?? null,
                    $validated['device_id'] ?? null,
                    $validated['request_id'] ?? null
                );

                $this->logSecurityEvent('attendance_recorded_success', [
                    'teacher_id' => $teacher->id,
                    'student_id' => $student->id,
                    'student_name' => $student->name,
                    'qr_age_seconds' => $verificationResult['age_seconds'],
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Absensi berhasil dicatat.',
                    'data' => $result,
                ], 201);
            });
        } catch (\App\Exceptions\AttendanceException $e) {
            // Business logic exceptions are safe to show
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            Log::channel('attendance_security')->error('Attendance scan error', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'fail',
                'message' => $e instanceof \Exception && !app()->isProduction()
                    ? $e->getMessage()
                    : 'Terjadi kesalahan saat memproses absensi.',
            ], 500);
        }
    }

    /**
     * Scan using encoded QR string (base64 JSON)
     *
     * Alternative endpoint for mobile apps that scan raw QR code content.
     *
     * @param Request $request
     * @return JsonResponse
     *
     * @example Request payload:
     * {
     *     "qr_encoded": "eyJzdHVkZW50X2lkIjoxMjMsIm5pc24iOiIwMDEyMzQ1Njc4IiwiZ2VuZXJhdGVkX2F0IjoxNzA2Njg4MDAwLCJzaWduYXR1cmUiOiJhMWIyYzNkNC4uLiJ9",
     *     "lat": -6.2088,
     *     "lng": 106.8456
     * }
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

        // STEP 1: Verify teacher role
        if (!$this->isTeacher($teacher)) {
            $this->logSecurityEvent('unauthorized_role', [
                'user_id' => $teacher->id,
                'role' => $teacher->role_type,
                'action' => 'scan_encoded_attempt',
            ]);

            return response()->json([
                'status' => 'fail',
                'message' => 'Unauthorized. Hanya guru yang dapat melakukan scan.',
            ], 403);
        }

        // STEP 2: Verify encoded QR
        try {
            $verificationResult = $this->signatureService->verifyEncodedPayload($validated['qr_encoded']);
        } catch (\Exception $e) {
            $this->logSecurityEvent('encoded_qr_verification_failed', [
                'teacher_id' => $teacher->id,
                'error' => $e->getMessage(),
                'payload_snippet' => substr($validated['qr_encoded'], 0, 20) . '...',
            ]);

            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], 400);
        }

        // Re-use the main scan logic with decoded payload
        $request->merge([
            'qr_payload' => [
                'student_id' => $verificationResult['student_id'],
                'nisn' => $verificationResult['nisn'] ?? '',
                'generated_at' => strtotime($verificationResult['generated_at']),
                'signature' => 'verified', // Already verified
            ],
        ]);

        // Process through main scan (signature already verified)
        return $this->processVerifiedScan($request, $verificationResult);
    }

    /**
     * Generate a new signed QR for a student (Admin/Teacher use)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateQR(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => 'required|integer|exists:users,id',
        ]);

        $user = $request->user();

        // Only admin/teacher can generate
        if (!in_array($user->role_type, ['teacher', 'homeroom_teacher', 'admin', 'school_admin'])) {
            return response()->json([
                'status' => 'fail',
                'message' => 'Unauthorized.',
            ], 403);
        }

        // Fetch student
        $student = \App\Models\User::where('id', $validated['student_id'])
            ->where('role_type', 'student')
            ->where('school_id', $user->school_id)
            ->firstOrFail();

        // Generate signed QR
        $qrData = $this->signatureService->createForStudent($student);

        Log::channel('attendance_security')->info('QR Generated', [
            'generator_id' => $user->id,
            'student_id' => $student->id,
            'expires_at' => $qrData['expires_at'],
        ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'qr_payload' => $qrData['payload'],
                'qr_encoded' => $qrData['encoded'],
                'expires_at' => $qrData['expires_at'],
                'valid_for_seconds' => $qrData['valid_for_seconds'],
            ],
        ]);
    }

    /**
     * Process a verified scan (signature already validated)
     */
    private function processVerifiedScan(Request $request, array $verificationResult): JsonResponse
    {
        $teacher = $request->user();
        $validated = $request->validated();

        try {
            return DB::transaction(function () use ($teacher, $verificationResult, $validated) {
                $student = \App\Models\User::where('id', $verificationResult['student_id'])
                    ->where('role_type', 'student')
                    ->where('school_id', $teacher->school_id)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();

                if (!$student) {
                    throw new \Exception('Siswa tidak ditemukan atau tidak aktif.');
                }

                $existingAttendance = \App\Models\Attendance::where('student_id', $student->id)
                    ->whereDate('attendance_date', today())
                    ->lockForUpdate()
                    ->first();

                if ($existingAttendance) {
                    throw new \Exception('Siswa sudah melakukan absensi hari ini.');
                }

                $result = $this->attendanceService->recordByTeacherScan(
                    $teacher,
                    $this->buildQrToken($student, $verificationResult),
                    $validated['lat'] ?? null,
                    $validated['lng'] ?? null,
                    $validated['device_id'] ?? null,
                    $validated['request_id'] ?? null
                );

                return response()->json([
                    'status' => 'success',
                    'message' => 'Absensi berhasil dicatat.',
                    'data' => $result,
                ], 201);
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'fail',
                'message' => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Build a compatible QR token for the attendance service
     */
    private function buildQrToken(\App\Models\User $student, array $verificationResult): string
    {
        $payload = [
            'sid' => $student->id,
            'sch' => $student->school_id,
            'iat' => strtotime($verificationResult['generated_at']),
            'typ' => 'student_card',
            'n' => \Illuminate\Support\Str::random(16), // Nonce for replay prevention
            'v' => 1,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        return $encoded . '.' . $signature;
    }

    /**
     * Check if user is a teacher
     */
    private function isTeacher($user): bool
    {
        return in_array($user->role_type, ['teacher', 'homeroom_teacher']);
    }

    /**
     * Mask NISN for privacy in logs
     */
    private function maskNisn(string $nisn): string
    {
        if (strlen($nisn) <= 4) {
            return str_repeat('*', strlen($nisn));
        }

        return substr($nisn, 0, 4) . str_repeat('*', strlen($nisn) - 4);
    }

    /**
     * Log security events to dedicated channel
     */
    private function logSecurityEvent(string $type, array $context): void
    {
        $severity = $this->getEventSeverity($type);

        Log::channel('attendance_security')->log(
            $severity === 'CRITICAL' ? 'critical' : ($severity === 'HIGH' ? 'error' : 'warning'),
            "Attendance Security: {$type}",
            array_merge($context, [
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'timestamp' => now()->toIso8601String(),
                'severity' => $severity,
            ])
        );
    }

    /**
     * Get severity level for event type
     */
    private function getEventSeverity(string $type): string
    {
        return match ($type) {
            'signature_verification_failed' => 'CRITICAL',
            'encoded_qr_verification_failed' => 'CRITICAL',
            'unauthorized_role' => 'HIGH',
            'student_not_found' => 'MEDIUM',
            'duplicate_attendance_attempt' => 'LOW',
            'attendance_recorded_success' => 'INFO',
            default => 'MEDIUM',
        };
    }
}
