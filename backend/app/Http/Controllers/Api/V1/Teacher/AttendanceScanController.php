<?php

namespace App\Http\Controllers\Api\V1\Teacher;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Carbon\Carbon;
use App\Models\TeachingSession;
use App\Models\Attendance;
use App\Models\Student;

class AttendanceScanController extends Controller
{
    public function scan(Request $request)
    {
        $request->validate([
            'qr_payload' => 'required|array',
            'qr_payload.session_id' => 'required|integer',
            'qr_payload.teacher_id' => 'required|integer',
            'qr_payload.generated_at' => 'required|string',
            'qr_payload.expires_at' => 'required|string',
            'qr_payload.signature' => 'required|string',
        ]);
        $student = $request->user();
        $payload = $request->input('qr_payload');
        $session = TeachingSession::findOrFail($payload['session_id']);
        $now = Carbon::now();
        // Validate signature
        $expectedSignature = hash_hmac('sha256', json_encode(array_except($payload, ['signature'])), config('app.key'));
        if (!hash_equals($expectedSignature, $payload['signature'])) {
            Log::channel('attendance')->warning('invalid_qr_signature', [
                'student_id' => $student->id,
                'session_id' => $session->id,
                'timestamp' => $now,
            ]);
            return response()->json(['success' => false, 'message' => 'Invalid QR signature'], 422);
        }
        // Check expiry
        if ($now->gt(Carbon::parse($payload['expires_at']))) {
            return response()->json(['success' => false, 'message' => 'QR expired'], 422);
        }
        // Check if already scanned
        $attendance = Attendance::where('session_id', $session->id)
            ->where('student_id', $student->id)
            ->first();
        if ($attendance && $attendance->status === 'present') {
            return response()->json(['success' => false, 'message' => 'Already checked in'], 409);
        }
        // Mark attendance
        $attendance = Attendance::updateOrCreate(
            [
                'session_id' => $session->id,
                'student_id' => $student->id,
            ],
            [
                'status' => 'present',
                'checked_in_at' => $now,
            ]
        );
        Log::channel('attendance')->info('student_checked_in', [
            'student_id' => $student->id,
            'session_id' => $session->id,
            'timestamp' => $now,
        ]);
        return response()->json(['success' => true, 'message' => 'Attendance recorded']);
    }
}
