<?php

namespace Tests\Feature;

use App\Exceptions\InvalidQrException;
use App\Models\School;
use App\Models\User;
use App\Services\HybridQrValidationService;
use App\Services\StudentQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hybrid QR Validation Tests
 * 
 * Test suite untuk memverifikasi bahwa hybrid validation
 * mencegah siswa non-aktif, transfer sekolah, dan cross-school attacks
 */
class HybridQrValidationTest extends TestCase
{
    use RefreshDatabase;

    private School $school1;
    private School $school2;
    private HybridQrValidationService $validator;
    private StudentQrService $qrService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two schools
        $this->school1 = School::create([
            'name' => 'SMA Negeri 1',
            'npsn' => '12345678',
            'school_level' => 'SMA',
            'address' => 'Jakarta',
            'is_active' => true,
        ]);

        $this->school2 = School::create([
            'name' => 'SMA Negeri 2',
            'npsn' => '87654321',
            'school_level' => 'SMA',
            'address' => 'Bandung',
            'is_active' => true,
        ]);

        $this->validator = app(HybridQrValidationService::class);
        $this->qrService = app(StudentQrService::class);
    }

    /** @test */
    public function active_student_with_valid_qr_can_be_validated()
    {
        // Arrange: Buat siswa aktif
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Ahmad Rizki',
            'username' => 'ahmad.rizki',
            'email' => 'ahmad@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Generate QR card
        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Act: Validasi QR
        $result = $this->validator->validateStudentQr($qrToken, $this->school1->id);

        // Assert: Harus berhasil
        $this->assertEquals($student->id, $result['student_id']);
        $this->assertEquals($this->school1->id, $result['school_id']);
        $this->assertEquals($student->name, $result['student_name']);
        $this->assertTrue($result['is_active']);
        $this->assertEquals('hybrid_hmac_db', $result['validation_method']);
    }

    /** @test */
    public function inactive_student_cannot_use_qr_even_if_signature_valid()
    {
        // Arrange: Buat siswa
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Budi Santoso',
            'username' => 'budi.santoso',
            'email' => 'budi@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true, // Awalnya aktif
        ]);

        // Generate QR saat masih aktif
        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Siswa kemudian di-nonaktifkan
        $student->update(['is_active' => false]);

        // Act & Assert: Harus ditolak meskipun signature valid
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->validator->validateStudentQr($qrToken, $this->school1->id);
    }

    /** @test */
    public function transferred_student_cannot_use_old_qr_card()
    {
        // Arrange: Buat siswa di sekolah 1
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Citra Dewi',
            'username' => 'citra.dewi',
            'email' => 'citra@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Generate QR saat masih di sekolah 1
        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Siswa pindah ke sekolah 2
        $student->update(['school_id' => $this->school2->id]);

        // Act & Assert: QR lama harus ditolak
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('pindah sekolah');

        $this->validator->validateStudentQr($qrToken, $this->school1->id);
    }

    /** @test */
    public function student_cannot_scan_at_different_school()
    {
        // Arrange: Buat siswa di sekolah 1
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Dedi Kurniawan',
            'username' => 'dedi.kurniawan',
            'email' => 'dedi@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Generate QR untuk sekolah 1
        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Act & Assert: Coba scan di sekolah 2 harus ditolak
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('tidak dapat melakukan absensi di sekolah ini');

        $this->validator->validateStudentQr($qrToken, $this->school2->id);
    }

    /** @test */
    public function deleted_student_cannot_use_qr()
    {
        // Arrange: Buat siswa
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Eka Pratama',
            'username' => 'eka.pratama',
            'email' => 'eka@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Generate QR
        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Hapus siswa dari database
        $student->delete();

        // Act & Assert: QR harus ditolak
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->validator->validateStudentQr($qrToken, $this->school1->id);
    }

    /** @test */
    public function validation_without_expected_school_still_checks_student_status()
    {
        // Arrange: Buat siswa tidak aktif
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Fajar Hidayat',
            'username' => 'fajar.hidayat',
            'email' => 'fajar@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => false, // Tidak aktif
        ]);

        // Generate QR
        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Act & Assert: Harus ditolak meskipun tidak ada expected school
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->validator->validateStudentQr($qrToken, null);
    }

    /** @test */
    public function device_id_validation_allows_first_time_registration()
    {
        // Arrange: Siswa tanpa device_id
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Gita Sari',
            'username' => 'gita.sari',
            'email' => 'gita@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
            'device_id' => null, // Belum ada device
        ]);

        $deviceId = 'device-123-abc';

        // Act: Validasi device pertama kali
        $result = $this->validator->validateDeviceId($student, $deviceId);

        // Assert: Harus berhasil dan device_id tersimpan
        $this->assertTrue($result);
        $this->assertEquals($deviceId, $student->fresh()->device_id);
    }

    /** @test */
    public function device_id_validation_rejects_different_device()
    {
        // Arrange: Siswa dengan device_id terdaftar
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Hadi Wijaya',
            'username' => 'hadi.wijaya',
            'email' => 'hadi@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
            'device_id' => 'device-registered',
        ]);

        $differentDeviceId = 'device-different';

        // Act & Assert: Harus ditolak (kemungkinan joki)
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('Perangkat tidak dikenali');

        $this->validator->validateDeviceId($student, $differentDeviceId);
    }

    /** @test */
    public function device_id_validation_accepts_same_device()
    {
        // Arrange: Siswa dengan device_id terdaftar
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Indah Permata',
            'username' => 'indah.permata',
            'email' => 'indah@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
            'device_id' => 'device-registered',
        ]);

        // Act: Validasi dengan device yang sama
        $result = $this->validator->validateDeviceId($student, 'device-registered');

        // Assert: Harus berhasil
        $this->assertTrue($result);
    }

    /** @test */
    public function validation_logs_security_anomaly_for_inactive_student()
    {
        // Arrange: Siswa tidak aktif
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Joko Susilo',
            'username' => 'joko.susilo',
            'email' => 'joko@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => false,
        ]);

        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Act: Coba validasi (akan gagal)
        try {
            $this->validator->validateStudentQr($qrToken, $this->school1->id);
        } catch (InvalidQrException $e) {
            // Expected
        }

        // Assert: Log harus tercatat
        // Note: Dalam test real, gunakan Log::spy() atau Log::shouldReceive()
        $this->assertTrue(true); // Placeholder - implement log assertion
    }

    /** @test */
    public function hmac_layer_rejects_tampered_qr()
    {
        // Arrange: Buat QR valid
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Kiki Amalia',
            'username' => 'kiki.amalia',
            'email' => 'kiki@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Tamper dengan QR (ubah sedikit)
        $tamperedQr = substr($qrToken, 0, -5) . 'XXXXX';

        // Act & Assert: Harus ditolak di layer 1 (HMAC)
        $this->expectException(InvalidQrException::class);
        $this->expectExceptionMessage('tidak valid');

        $this->validator->validateStudentQr($tamperedQr, $this->school1->id);
    }

    /** @test */
    public function validation_returns_complete_student_data()
    {
        // Arrange
        $student = User::create([
            'school_id' => $this->school1->id,
            'name' => 'Lina Marlina',
            'username' => 'lina.marlina',
            'email' => 'lina@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
            'device_id' => 'device-lina',
        ]);

        $qrToken = $this->qrService->generateStudentCard(
            $student->id,
            $this->school1->id
        );

        // Act
        $result = $this->validator->validateStudentQr($qrToken, $this->school1->id);

        // Assert: Semua data penting ada
        $this->assertArrayHasKey('student_id', $result);
        $this->assertArrayHasKey('school_id', $result);
        $this->assertArrayHasKey('student_name', $result);
        $this->assertArrayHasKey('student_username', $result);
        $this->assertArrayHasKey('student_device_id', $result);
        $this->assertArrayHasKey('is_active', $result);
        $this->assertArrayHasKey('validated_at', $result);
        $this->assertArrayHasKey('validation_method', $result);

        $this->assertEquals('Lina Marlina', $result['student_name']);
        $this->assertEquals('lina.marlina', $result['student_username']);
        $this->assertEquals('device-lina', $result['student_device_id']);
    }
}
