<?php

namespace App\Core\Services\Attendance;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class QRCodeService
{
    /**
     * Generate dynamic secure QR Payload for class/schedule
     */
    public function generatePayload(int $scheduleId, ?float $lat = null, ?float $lng = null): string
    {
        $nonce = Str::random(8); // Anti-replay nonce

        $payload = [
            'v' => 1,
            't' => 'cls',
            'id' => $scheduleId,
            'ts' => Carbon::now()->timestamp,
            'exp' => Carbon::now()->addSeconds(30)->timestamp,
            'geo' => ($lat && $lng) ? ['lat' => $lat, 'lng' => $lng, 'rad' => 100] : null,
            'n' => $nonce,
        ];

        // Encrypt payload using App Key (AES-256-CBC)
        $token = Crypt::encrypt($payload);

        // Store nonce in Redis for 30 seconds to prevent huge replay window if needed
        // (Optional: depending on strictness)
        // Redis::setex("qr_nonce:{$nonce}", 30, 'active');

        return $token;
    }

    /**
     * Generate dynamic secure QR Payload for teacher attendance
     * This QR is unique to each teacher and used for self check-in/out
     */
    public function generateTeacherPayload(int $teacherId, int $schoolId): string
    {
        $nonce = Str::random(8); // Anti-replay nonce

        $payload = [
            'v' => 1,
            't' => 'teacher',
            'teacher_id' => $teacherId,
            'school_id' => $schoolId,
            'ts' => Carbon::now()->timestamp,
            'exp' => Carbon::now()->addSeconds(30)->timestamp,
            'n' => $nonce,
        ];

        // Encrypt payload using App Key (AES-256-CBC)
        return Crypt::encrypt($payload);
    }

    /**
     * Decrypt and validate QR Payload
     *
     * @throws Exception
     */
    public function decryptAndValidate(string $token): array
    {
        try {
            $data = Crypt::decrypt($token);
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            throw new Exception('Token QR tidak valid atau rusak.');
        }

        // 1. Time Validation (Max 30 seconds age)
        $createdAt = Carbon::createFromTimestamp($data['ts']);
        $diff = $createdAt->diffInSeconds(Carbon::now());

        if ($diff > 30) {
            throw new Exception("QR Code sudah kadaluarsa ({$diff} detik). Silakan scan ulang.");
        }

        // 2. Nonce Check (prevent replay if we used Redis)
        // if (!Redis::exists("qr_nonce:{$data['nonce']}")) { ... }

        return $data;
    }
}
