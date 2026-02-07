<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\AttendanceException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\TeacherScanRequest;
use App\Services\AttendanceCheckInService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * TeacherScanController
 *
 * CLEAN ARCHITECTURE:
 * - Controller hanya menerima FormRequest
 * - Memanggil 1 service method
 * - Return JSON response
 *
 * Authorization handled by:
 * - FormRequest::authorize() for role check
 * - Middleware for rate limiting
 * - Service for business rules
 */
class TeacherScanController extends Controller
{
    public function __construct(
        private AttendanceCheckInService $checkInService
    ) {}

    /**
     * Teacher Scans Student QR Card
     *
     * @param TeacherScanRequest $request Validated & Authorized request
     * @return JsonResponse
     */
    public function scan(TeacherScanRequest $request): JsonResponse
    {
        try {
            // 1. Get validated data with defaults (request_id auto-generated if missing)
            $data = $request->validatedWithDefaults();

            // 2. Delegate ALL logic to service
            $result = $this->checkInService->teacherCheckIn(
                teacher: $request->user(),
                qrToken: $data['qr_token'],
                data: [
                    'lat' => $data['lat'] ?? null,
                    'lng' => $data['lng'] ?? null,
                    'device_id' => $data['device_id'] ?? null,
                    'request_id' => $data['request_id'],
                ],
                request: $request
            );

            // 3. Return standardized success response
            return response()->json([
                'success' => true,
                'message' => $result->message,
                'data' => [
                    'attendance' => $result->attendance,
                    'status' => $result->status,
                    'is_retry' => $result->isIdempotentRetry,
                ],
            ], $result->isIdempotentRetry ? 200 : 201);

        } catch (AttendanceException $e) {
            // Business logic exceptions - safe to show to user
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => $this->getErrorCode($e),
                'data' => null,
            ], 400);

        } catch (\Exception $e) {
            // Unexpected errors - log and return generic message
            Log::error('TeacherScan unexpected error', [
                'teacher_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Terjadi kesalahan saat memproses absensi.',
                'code' => 'INTERNAL_ERROR',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Map exception to error code
     */
    private function getErrorCode(AttendanceException $e): string
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
            default => 'ATTENDANCE_ERROR',
        };
    }
}
