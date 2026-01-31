<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AttendanceService;
use Illuminate\Http\Request;

class TeacherScanController extends Controller
{
    public function __construct(
        private AttendanceService $attendanceService
    ) {}

    /**
     * Teacher Scans Student QR Card
     *
     * All business logic delegated to AttendanceService.
     */
    public function scan(Request $request)
    {
        $request->validate([
            'qr_token' => 'required|string',
            'lat' => 'nullable|numeric',
            'lng' => 'nullable|numeric',
            'request_id' => 'nullable|uuid',
        ]);

        $teacher = $request->user();

        // 1. Verify Teacher Role (Redundant if middleware used, but safe)
        if (! $teacher->hasRole(['teacher', 'homeroom_teacher'])) {
            return response()->json(['message' => 'Unauthorized. Hanya guru yang bisa scan.'], 403);
        }

        try {
            $result = $this->attendanceService->recordByTeacherScan(
                $teacher,
                $request->input('qr_token'),
                $request->input('lat'),
                $request->input('lng'),
                null, // deviceId
                $request->input('request_id')
            );

            return response()->json([
                'message' => 'Absensi berhasil dicatat.',
                'data' => $result,
            ], 201);

        } catch (\App\Exceptions\AttendanceException $e) {
            // Business logic exceptions are safe to show to users
            return response()->json([
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            // SECURITY FIX: Log unexpected errors, show generic message
            \Illuminate\Support\Facades\Log::error('TeacherScan unexpected error', [
                'teacher_id' => $request->user()->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Terjadi kesalahan saat memproses absensi.',
            ], 500);
        }
    }
}
