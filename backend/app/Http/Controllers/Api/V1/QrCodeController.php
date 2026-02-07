<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\QrCode\CloseQrRequest;
use App\Http\Requests\QrCode\GenerateQrRequest;
use App\Models\QrCode;
use App\Models\Schedule;
use App\Services\QrService;
use App\Traits\ValidatesSchoolOwnership;

class QrCodeController extends Controller
{
    use ValidatesSchoolOwnership;

    public function __construct(
        private QrService $qrService
    ) {}

    /**
     * Generate QR Code for attendance (Teacher only)
     */
    public function generate(GenerateQrRequest $request)
    {
        $schedule = Schedule::findOrFail($request->input('schedule_id'));

        // SECURITY: Validate schedule belongs to same school
        $this->validateSchoolOwnership($schedule, 'Jadwal tidak ditemukan atau bukan milik sekolah Anda.');

        // Authorization check via policy
        $this->authorize('create', QrCode::class);

        // Generate unique nonce for this QR session
        $nonce = bin2hex(random_bytes(16));

        // Create QR record in database
        $qrCode = QrCode::create([
            'school_id' => $request->user()->school_id,
            'schedule_id' => $schedule->id,
            'qr_type' => $request->input('qr_type', 'in'),
            'valid_from' => now(),
            'valid_until' => now()->addMinutes($request->input('expiry_minutes', config('qr.expiry_minutes', 10))),
            'max_scans' => $request->input('max_scans', $schedule->class->student_count ?? 50),
            'scan_count' => 0,
            'is_active' => true,
            'nonce' => $nonce, // Add nonce for race condition prevention
        ]);

        // Generate stateless token with nonce
        $token = $this->qrService->generate([
            'schedule_id' => $schedule->id,
            'qr_id' => $qrCode->id,
            'type' => $qrCode->qr_type,
            'nonce' => $nonce, // Include nonce in token
        ]);

        // Store token for reference (optional)
        $qrCode->update(['token' => $token]);

        return response()->success([
            'qr_code' => [
                'id' => $qrCode->id,
                'token' => $token,
                'valid_until' => $qrCode->valid_until,
                'max_scans' => $qrCode->max_scans,
                'qr_type' => $qrCode->qr_type,
                'nonce' => $nonce, // Return nonce for client validation
            ],
        ], 'QR Code berhasil dibuat', 201);
    }

    /**
     * Close/deactivate QR Code (Teacher only)
     */
    public function close(CloseQrRequest $request)
    {
        $validated = $request->validated();
        $qrCode = QrCode::findOrFail($validated['qr_code_id']);

        // SECURITY: Validate QR code belongs to same school
        $this->validateSchoolOwnership($qrCode, 'QR Code tidak ditemukan atau bukan milik sekolah Anda.');

        // Authorization check
        $this->authorize('close', $qrCode);

        $qrCode->update(['is_active' => false]);

        return response()->success([], 'QR Code berhasil ditutup');
    }

    /**
     * Show QR Code details (Teacher only)
     */
    public function show(QrCode $qrCode)
    {
        $this->authorize('view', $qrCode);

        return response()->success([
            'qr_code' => $qrCode->load('schedule.subject', 'schedule.class'),
        ]);
    }
}
