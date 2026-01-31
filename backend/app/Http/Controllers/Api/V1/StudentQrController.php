<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StudentQrService;
use Illuminate\Http\Request;

class StudentQrController extends Controller
{
    public function __construct(
        private StudentQrService $qrService
    ) {}

    /**
     * Get My QR Card (Student Only)
     */
    public function myQrCard(Request $request)
    {
        $user = $request->user();

        if ($user->role_type !== 'student') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $token = $this->qrService->generate($user);

        return response()->json([
            'qr_token' => $token,
            'description' => 'Secure Student ID QR',
            'valid_until' => now()->addYear()->toDateTimeString(),
        ]);
    }

    /**
     * Generate QR Card for specific student (Admin/Teacher Only)
     */
    public function show(Request $request, $studentId)
    {
        $user = $request->user();

        // Authorization: Only School Admin or Teacher from same school
        $student = User::where('id', $studentId)
            ->where('school_id', $user->school_id)
            ->where('role_type', 'student')
            ->firstOrFail();

        $token = $this->qrService->generate($student);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'nis' => $student->username,
            ],
            'qr_token' => $token,
            'valid_until' => now()->addYear()->toDateTimeString(),
        ]);
    }

    /**
     * Verify QR Card (Admin/System Only)
     */
    public function verify(Request $request)
    {
        $request->validate([
            'qr_token' => 'required|string',
        ]);

        try {
            $payload = $this->qrService->verify($request->input('qr_token'));

            $student = User::find($payload['sid']);

            if (! $student) {
                return response()->json(['message' => 'Siswa tidak ditemukan.'], 404);
            }

            return response()->json([
                'valid' => true,
                'student' => [
                    'id' => $student->id,
                    'name' => $student->name,
                    'nis' => $student->username,
                    'class_id' => $student->class_id,
                ],
                'metadata' => $payload,
            ]);

        } catch (\App\Exceptions\QrValidationException $e) {
            // QR validation errors are safe to show to users
            return response()->json([
                'valid' => false,
                'message' => $e->getMessage(),
            ], 400);
        } catch (\Exception $e) {
            // SECURITY FIX: Log unexpected errors, show generic message
            \Illuminate\Support\Facades\Log::error('StudentQr validation unexpected error', [
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'valid' => false,
                'message' => 'Terjadi kesalahan saat memvalidasi QR.',
            ], 500);
        }
    }
}
