<?php

namespace App\Services;

use App\Exceptions\InvalidQrException;
use App\Exceptions\QrExpiredException;
use App\Models\StudentCard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Student QR Service - Handles student QR card verification
 *
 * CRITICAL: This service handles student QR card validation for attendance
 */
final class StudentQrService
{
    /**
     * Verify student QR token from their ID card
     *
     * @param  string  $qrToken  The QR token from student card
     * @return array Decoded payload with student info
     *
     * @throws \Exception If QR is invalid or expired
     */
    public function verify(string $qrToken): array
    {
        // CHECK FOR NEW SECURE CARD FORMAT (card_id|token)
        if (str_contains($qrToken, '|') && ! str_contains($qrToken, '.')) {
            return $this->verifySecureCard($qrToken);
        }

        try {
            // 1. Check format (student QR format: base64.signature)
            if (! str_contains($qrToken, '.')) {
                throw new InvalidQrException('Format QR tidak valid');
            }

            [$encoded, $signature] = explode('.', $qrToken, 2);

            // 2. Verify HMAC signature
            $expectedSignature = hash_hmac('sha256', $encoded, config('qr.secret'));

            if (! hash_equals($expectedSignature, $signature)) {
                throw new InvalidQrException('Tanda tangan QR tidak valid');
            }

            // 3. Decode payload
            $payload = json_decode(base64_decode($encoded), true);

            if (! $payload || ! is_array($payload)) {
                throw new InvalidQrException('Data QR rusak');
            }

            // 4. Check required fields for student QR
            $requiredFields = ['sid', 'sch', 'iat'];
            foreach ($requiredFields as $field) {
                if (! isset($payload[$field])) {
                    throw new InvalidQrException("Field QR hilang: {$field}");
                }
            }

            // 5. Check expiry if exists (student QR cards may not expire)
            // Note: Using UTC for token expiry check (standard practice)
            if (isset($payload['exp']) && $payload['exp'] < now()->timestamp) {
                throw new QrExpiredException('QR Card sudah kadaluarsa');
            }

            // 6. Check not too old (prevent very old student cards)
            $maxAge = config('qr.student_card_max_age_days', 365) * 24 * 3600; // 1 year default
            if ($payload['iat'] < (now()->timestamp - $maxAge)) {
                throw new InvalidQrException('QR Card terlalu lama, perlu diperbaharui');
            }

            return $payload;

        } catch (InvalidQrException|QrExpiredException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \Exception('QR Code tidak dapat dibaca: '.$e->getMessage());
        }
    }

    /**
     * Verify new secure card format (card_id|token)
     */
    private function verifySecureCard(string $qrToken): array
    {
        try {
            [$cardId, $token] = explode('|', $qrToken, 2);

            if (empty($cardId) || empty($token)) {
                throw new InvalidQrException('Format kartu tidak valid.');
            }

            $card = StudentCard::find($cardId);

            if (! $card) {
                throw new InvalidQrException('Kartu tidak ditemukan.');
            }

            if (! $card->is_active) {
                throw new InvalidQrException('Kartu ini sudah dinonaktifkan.');
            }

            // Verify Hash using HMAC sha256 with APP_KEY as seed
            $expectedHash = hash_hmac('sha256', $token, config('app.key'));

            if (! hash_equals($card->qr_hash, $expectedHash)) {
                $this->logSecurityAnomaly('invalid_card_hash', [
                    'card_id' => $cardId,
                    'reason' => 'Hash mismatch for valid card ID',
                ]);
                throw new InvalidQrException('Token kartu tidak valid.');
            }

            return [
                'sid' => $card->student_id,
                'sch' => $card->school_id,
                'iat' => $card->issued_at->timestamp,
                'typ' => 'student_card',
                'card_id' => $card->id, // Extra metadata
            ];

        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Validate student status after HMAC verification
     * CRITICAL: Prevents bypassing security checks with valid signatures
     *
     * @param  array  $payload  Verified QR payload
     * @param  int  $expectedSchoolId  Expected school ID to match
     * @return \App\Models\User The validated student
     *
     * @throws \Exception If student status is invalid
     */
    public function validateStudentStatus(array $payload, int $expectedSchoolId): \App\Models\User
    {
        $studentId = $payload['sid'];
        $qrSchoolId = $payload['sch'];

        // 1. Fetch student from database
        $student = \App\Models\User::where('id', $studentId)
            ->where('role_type', 'student')
            ->first();

        // CRITICAL: Log anomaly if signature valid but student not found
        if (! $student) {
            $this->logSecurityAnomaly('student_not_found_after_hmac', [
                'student_id' => $studentId,
                'qr_school_id' => $qrSchoolId,
                'expected_school_id' => $expectedSchoolId,
                'severity' => 'HIGH',
                'reason' => 'Valid HMAC signature but student does not exist in database',
            ]);

            throw new \Exception('Siswa tidak ditemukan. QR Card mungkin tidak valid.');
        }

        // CRITICAL: Check if student is active
        if (! $student->is_active) {
            $this->logSecurityAnomaly('inactive_student_attempted_scan', [
                'student_id' => $studentId,
                'student_name' => $student->name,
                'is_active' => false,
                'qr_school_id' => $qrSchoolId,
                'severity' => 'MEDIUM',
                'reason' => 'Valid HMAC signature but student account is inactive',
            ]);

            throw new \Exception('Akun siswa tidak aktif. Hubungi administrator.');
        }

        // CRITICAL: Verify school membership matches QR
        if ($student->school_id != $qrSchoolId) {
            $this->logSecurityAnomaly('school_mismatch_in_qr', [
                'student_id' => $studentId,
                'student_name' => $student->name,
                'student_school_id' => $student->school_id,
                'qr_school_id' => $qrSchoolId,
                'severity' => 'HIGH',
                'reason' => 'Student school_id does not match QR payload school_id',
            ]);

            throw new \Exception('QR Card tidak sesuai dengan data sekolah siswa.');
        }

        // CRITICAL: Verify expected school matches
        if ($student->school_id != $expectedSchoolId) {
            $this->logSecurityAnomaly('cross_school_attempt', [
                'student_id' => $studentId,
                'student_name' => $student->name,
                'student_school_id' => $student->school_id,
                'expected_school_id' => $expectedSchoolId,
                'qr_school_id' => $qrSchoolId,
                'severity' => 'CRITICAL',
                'reason' => 'Student attempting to scan at different school',
            ]);

            throw new \Exception('QR Card dari sekolah lain.');
        }

        // All validations passed
        return $student;
    }

    /**
     * Log security anomaly to dedicated channel
     * CRITICAL: Tracks suspicious activity with valid signatures
     */
    private function logSecurityAnomaly(string $type, array $context): void
    {
        \Illuminate\Support\Facades\Log::channel('security')->warning(
            "QR Security Anomaly: {$type}",
            array_merge($context, [
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'timestamp' => now()->toIso8601String(), // UTC for security logs
            ])
        );

        // Also create audit log if possible
        try {
            if (isset($context['student_id'])) {
                \App\Models\AuditLog::create([
                    'user_id' => $context['student_id'],
                    'school_id' => $context['student_school_id'] ?? $context['qr_school_id'] ?? null,
                    'action' => 'qr_security_anomaly',
                    'description' => "QR Anomaly: {$type} - {$context['reason']}",
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                ]);
            }
        } catch (\Exception $e) {
            // Don't fail the request if audit log fails
        }
    }

    /**
     * Generate student QR card token
     *
     * @return string QR token for student card
     */
    public function generateStudentCard(int $studentId, int $schoolId): string
    {
        $payload = [
            'sid' => $studentId,      // Student ID
            'sch' => $schoolId,       // School ID
            'typ' => 'student_card',  // Type
            'iat' => now()->timestamp, // UTC for token timestamps (standard)
            'nonce' => Str::random(16),
            'v' => 1,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        return $encoded.'.'.$signature;
    }
}
