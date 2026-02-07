<?php

namespace App\Services;

use App\Exceptions\InvalidQrException;
use App\Exceptions\QrExpiredException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Hybrid QR Validation Service
 *
 * Strategi 2-Layer:
 * 1. HMAC Validation (cepat, tanpa DB) - Layer pertama
 * 2. Database Verification (1 query minimal) - Layer kedua untuk keamanan
 *
 * Ini mencegah:
 * - Siswa yang sudah dikeluarkan tetap bisa absen
 * - QR card lama masih valid setelah transfer sekolah
 * - Akun yang di-nonaktifkan masih bisa scan
 */
final class HybridQrValidationService
{
    public function __construct(
        private StudentQrService $studentQrService
    ) {}

    /**
     * Validasi QR dengan 2 layer: HMAC + Database
     *
     * @param  string  $qrToken  Token QR dari kartu siswa
     * @param  int|null  $expectedSchoolId  School ID yang diharapkan (dari context scan)
     * @return array Payload yang sudah divalidasi dengan data siswa
     *
     * @throws InvalidQrException
     * @throws QrExpiredException
     */
    public function validateStudentQr(string $qrToken, ?int $expectedSchoolId = null): array
    {
        // ========================================
        // LAYER 1: HMAC VALIDATION (STATELESS)
        // ========================================
        // Cepat, tidak hit database
        $payload = $this->studentQrService->verify($qrToken);

        $studentId = $payload['sid'];
        $qrSchoolId = $payload['sch'];

        // ========================================
        // LAYER 2: DATABASE VERIFICATION (1 QUERY)
        // ========================================
        // Validasi bahwa siswa masih valid dan aktif

        $student = User::where('id', $studentId)
            ->where('role_type', 'student')
            ->select('id', 'school_id', 'is_active', 'name', 'username', 'device_id')
            ->first();

        // Validasi 1: Siswa tidak ditemukan
        if (! $student) {
            $this->logSecurityAnomaly('student_not_found', [
                'student_id' => $studentId,
                'qr_school_id' => $qrSchoolId,
                'reason' => 'QR signature valid but student does not exist in database',
            ]);

            throw new InvalidQrException('Siswa tidak ditemukan. QR Card mungkin sudah tidak valid.');
        }

        // Validasi 2: Siswa tidak aktif
        if (! $student->is_active) {
            $this->logSecurityAnomaly('inactive_student_scan', [
                'student_id' => $studentId,
                'student_name' => $student->name,
                'school_id' => $student->school_id,
                'reason' => 'QR signature valid but student account is inactive',
            ]);

            throw new InvalidQrException('Akun siswa tidak aktif. Silakan hubungi admin sekolah.');
        }

        // Validasi 3: School ID tidak cocok (siswa sudah pindah sekolah)
        if ($student->school_id !== $qrSchoolId) {
            $this->logSecurityAnomaly('school_mismatch', [
                'student_id' => $studentId,
                'student_name' => $student->name,
                'qr_school_id' => $qrSchoolId,
                'current_school_id' => $student->school_id,
                'reason' => 'QR signature valid but student has been transferred to different school',
            ]);

            throw new InvalidQrException('QR Card tidak valid. Siswa sudah pindah sekolah.');
        }

        // Validasi 4: Jika ada expected school (dari context scanning), harus cocok
        if ($expectedSchoolId !== null && $student->school_id !== $expectedSchoolId) {
            $this->logSecurityAnomaly('cross_school_scan_attempt', [
                'student_id' => $studentId,
                'student_name' => $student->name,
                'student_school_id' => $student->school_id,
                'expected_school_id' => $expectedSchoolId,
                'reason' => 'Student trying to scan QR at different school',
            ]);

            throw new InvalidQrException('Anda tidak dapat melakukan absensi di sekolah ini.');
        }

        // ========================================
        // SEMUA VALIDASI BERHASIL
        // ========================================

        // Gabungkan payload QR dengan data siswa dari database
        return [
            // Data dari QR payload
            'student_id' => $studentId,
            'school_id' => $qrSchoolId,
            'qr_issued_at' => $payload['iat'],
            'qr_type' => $payload['typ'] ?? 'student_card',
            'qr_version' => $payload['v'] ?? 1,

            // Data dari database (fresh)
            'student_name' => $student->name,
            'student_username' => $student->username,
            'student_device_id' => $student->device_id,
            'is_active' => $student->is_active,

            // Metadata validasi
            'validated_at' => now()->toIso8601String(),
            'validation_method' => 'hybrid_hmac_db',
        ];
    }

    /**
     * Validasi QR schedule (untuk QR yang di-generate guru)
     *
     * @param  string  $qrToken  Token QR dari schedule
     * @param  int|null  $expectedSchoolId  School ID yang diharapkan
     * @return array Payload yang sudah divalidasi
     */
    public function validateScheduleQr(string $qrToken, ?int $expectedSchoolId = null): array
    {
        // Untuk schedule QR, gunakan QrService yang sudah ada
        $qrService = app(\App\Services\QrService::class);

        // Layer 1: HMAC validation
        $payload = $qrService->validate($qrToken);

        $scheduleId = $payload->scheduleId;

        // Layer 2: Database verification
        $schedule = \App\Models\Schedule::where('id', $scheduleId)
            ->where('is_active', true)
            ->select('id', 'school_id', 'class_id', 'teacher_id', 'subject_id')
            ->first();

        if (! $schedule) {
            $this->logSecurityAnomaly('schedule_not_found', [
                'schedule_id' => $scheduleId,
                'reason' => 'QR signature valid but schedule does not exist or inactive',
            ]);

            throw new InvalidQrException('Jadwal tidak ditemukan atau sudah tidak aktif.');
        }

        // Validasi school context
        if ($expectedSchoolId !== null && $schedule->school_id !== $expectedSchoolId) {
            $this->logSecurityAnomaly('cross_school_schedule_scan', [
                'schedule_id' => $scheduleId,
                'schedule_school_id' => $schedule->school_id,
                'expected_school_id' => $expectedSchoolId,
                'reason' => 'Schedule QR scanned at different school',
            ]);

            throw new InvalidQrException('QR ini bukan untuk sekolah Anda.');
        }

        return [
            'schedule_id' => $scheduleId,
            'school_id' => $schedule->school_id,
            'class_id' => $schedule->class_id,
            'teacher_id' => $schedule->teacher_id,
            'subject_id' => $schedule->subject_id,
            'qr_type' => $payload->type,
            'validated_at' => now()->toIso8601String(),
            'validation_method' => 'hybrid_hmac_db',
        ];
    }

    /**
     * Log anomali keamanan untuk monitoring
     *
     * @param  string  $anomalyType  Tipe anomali
     * @param  array  $context  Data konteks
     */
    private function logSecurityAnomaly(string $anomalyType, array $context): void
    {
        Log::warning('QR Security Anomaly Detected', [
            'anomaly_type' => $anomalyType,
            'timestamp' => now()->toIso8601String(),
            'context' => $context,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        // Opsional: Kirim ke monitoring service (Sentry, etc)
        if (app()->bound('sentry')) {
            app('sentry')->captureMessage("QR Anomaly: {$anomalyType}", [
                'level' => 'warning',
                'extra' => $context,
            ]);
        }
    }

    /**
     * Validasi device ID untuk anti-joki
     *
     * @param  User  $student  Siswa yang melakukan scan
     * @param  string|null  $scanDeviceId  Device ID dari scan request
     * @return bool True jika valid
     *
     * @throws InvalidQrException
     */
    public function validateDeviceId(User $student, ?string $scanDeviceId): bool
    {
        // Jika siswa belum punya device_id terdaftar, izinkan dan register
        if (empty($student->device_id)) {
            if ($scanDeviceId) {
                $student->update(['device_id' => $scanDeviceId]);

                Log::info('Device ID registered for student', [
                    'student_id' => $student->id,
                    'device_id' => $scanDeviceId,
                ]);
            }

            return true;
        }

        // Jika sudah ada device_id, harus cocok
        if ($student->device_id !== $scanDeviceId) {
            $this->logSecurityAnomaly('device_id_mismatch', [
                'student_id' => $student->id,
                'student_name' => $student->name,
                'registered_device_id' => $student->device_id,
                'scan_device_id' => $scanDeviceId,
                'reason' => 'Student trying to scan with different device (possible joki attempt)',
            ]);

            throw new InvalidQrException(
                'Perangkat tidak dikenali. Harap gunakan HP Anda sendiri yang terdaftar. '.
                'Jika Anda berganti HP, hubungi admin sekolah.'
            );
        }

        return true;
    }

    /**
     * Get validation statistics untuk monitoring
     *
     * @param  int  $schoolId  School ID
     * @param  string  $period  Period (today, week, month)
     * @return array Statistics
     */
    public function getValidationStats(int $schoolId, string $period = 'today'): array
    {
        // Implementasi untuk monitoring dashboard
        // Bisa digunakan untuk melihat berapa banyak anomali yang terdeteksi

        $dateFilter = match ($period) {
            'today' => now()->startOfDay(),
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            default => now()->startOfDay(),
        };

        // Query dari log atau buat tabel khusus untuk tracking
        // Untuk sekarang, return placeholder
        return [
            'period' => $period,
            'school_id' => $schoolId,
            'total_scans' => 0, // Implementasi nanti
            'successful_validations' => 0,
            'failed_validations' => 0,
            'anomalies_detected' => 0,
            'anomaly_types' => [],
        ];
    }
}
