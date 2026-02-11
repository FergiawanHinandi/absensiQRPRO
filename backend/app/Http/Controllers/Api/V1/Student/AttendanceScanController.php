<?php

namespace App\Http\Controllers\Api\V1\Student;

use App\Http\Controllers\Controller;
use App\Http\Middleware\QRValidation;
use App\Services\AttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceScanController extends Controller
{
    public function __construct(
        protected AttendanceService $attendanceService
    ) {
        // Enforce throttle and QR validation middleware
        $this->middleware(['throttle:30,1', QRValidation::class]);
    }


    use \App\Traits\HandlesIdempotency;

    /**
     * Process valid QR scan from Student
     */
    public function scan(Request $request): JsonResponse
    {
        // 1. Validate Payload via Middleware (QRValidation)
        // Note: Middleware handles signature integrity.
        // We only extract what we need.
        $payload = $request->only(['school_id', 'session_id', 'expires_at']);

        // 2. Wrap Logic in Idempotency Handler (using query/header key)
        return $this->withIdempotency($request, function () use ($request, $payload) {
            try {
                // Determine source (mobile app vs manual scan)
                $source = $request->input('source', 'qr');

                $attendance = $this->attendanceService->recordStudentAttendance(
                    $request->user(),
                    $payload,
                    // If method signature supports it.. 
                    // To keep "clean version" we assume methods handle what they need.
                    // But wait, the Service method I wrote earlier didn't take `source` argument. 
                    // It had: recordStudentAttendance(User $student, array $payload): Attendance
                );

                // 3. Return Consistent Success Structure
                // { status: 'success', message: '...', code: 200, data: {...} }
                // User asked for { status, message, code }
                // Adding 'data' is usually standard.
                return response()->json([
                    'status' => 'success',
                    'message' => 'Absensi berhasil dicatat.',
                    'code' => 200,
                    'data' => [
                        'attendance_id' => $attendance->id,
                        'student_name' => $request->user()->name,
                        'check_in_time' => $attendance->check_in_time->format('H:i:s'),
                        'status' => $attendance->status,
                        // Idempotency: Add key to response for client validation
                        'request_id' => $request->input('idempotency_key')
                    ]
                ], 200);

            } catch (\App\Exceptions\AttendanceException $e) {
                // 4. Handle Business Logic Errors (e.g. Schedule Not Found, Late)
                // These are predictable client errors (4xx)
                return response()->json([
                    'status' => 'error',
                    'message' => $e->getMessage(),
                    'code' => 400
                ], 400);

            } catch (\Exception $e) {
                // 5. Handle Unexpected Errors (5xx)
                // Log internal error but return safe message
                // Or if it's "Duplicate Entry" (Integrity constraint check)
                if (str_contains($e->getMessage(), 'Duplicate entry') || $e->getCode() === "23000") {
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Absensi duplikat terdeteksi.',
                        'code' => 409 // Conflict
                    ], 409);
                }

                return response()->json([
                    'status' => 'error',
                    'message' => 'Terjadi kesalahan sistem.', // Safe message
                    'debug_message' => config('app.debug') ? $e->getMessage() : null,
                    'code' => 500
                ], 500);
            }
        }, 15); // Cache success result for 15 seconds (short-lived idempotency for quick retry)
    }
}
