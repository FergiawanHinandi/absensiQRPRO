<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\QrCode;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceScanTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private School $school;

    private Schedule $schedule;

    private QrService $qrService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qrService = app(QrService::class);

        // Create school
        $this->school = School::create([
            'name' => 'SMP Test',
            'npsn' => '12345678',
            'school_level' => 'SMP',
            'address' => 'Test Address',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'radius_meters' => 100,
            'is_active' => true,
        ]);

        // Create teacher
        $this->teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher1',
            'name' => 'Teacher Test',
            'email' => 'teacher@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create student
        $this->student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student1',
            'name' => 'Student Test',
            'email' => 'student@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create schedule
        $this->schedule = Schedule::create([
            'school_id' => $this->school->id,
            'academic_year_id' => 1, // Default academic year
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => '07:00:00',
            'end_time' => '08:30:00',
            'room' => 'Room 1',
        ]);
    }

    /**
     * TEST 1: Scan Valid QR Code
     *
     * @test
     */
    public function test_scan_valid_qr_code_success()
    {
        // Create active QR code
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now(),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        // Generate valid token
        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        // Scan QR
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'token' => $token,
                'latitude' => -6.200000, // Within radius
                'longitude' => 106.816666,
                'accuracy' => 10,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Absensi berhasil',
            ]);

        // Verify attendance created
        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_type' => 'in',
            'is_manual' => false,
        ]);

        // Verify scan count incremented
        $this->assertEquals(1, $qrCode->fresh()->scan_count);
    }

    /**
     * TEST 2: Scan Duplicate (Already Scanned)
     *
     * @test
     */
    public function test_scan_duplicate_qr_code_rejected()
    {
        // Create QR code
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now(),
            'valid_until' => now()->addMinutes(10),
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        // Create existing attendance (already scanned)
        Attendance::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'in',
            'status' => 'present',
            'check_in_time' => now(),
            'is_manual' => false,
        ]);

        // Try to scan again
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'token' => $token,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Already scanned for this schedule',
            ]);
    }

    /**
     * TEST 3: Scan Expired QR Code
     *
     * @test
     */
    public function test_scan_expired_qr_code_rejected()
    {
        // Create expired QR code
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(20),
            'valid_until' => now()->subMinutes(10), // Expired
            'is_active' => true,
        ]);

        // Generate token with expired timestamp
        $expiredPayload = [
            'sid' => $this->schedule->id,
            'qid' => $qrCode->id,
            'typ' => 'in',
            'iat' => now()->subMinutes(20)->timestamp,
            'exp' => now()->subMinutes(10)->timestamp, // Expired
            'nonce' => 'test123',
            'v' => 1,
        ];

        $encoded = base64_encode(json_encode($expiredPayload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));
        $expiredToken = $encoded.'.'.$signature;

        // Try to scan expired QR
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'token' => $expiredToken,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'QR code sudah kadaluarsa',
            ]);
    }

    /**
     * TEST 4: Scan Outside School Area (GPS Validation)
     *
     * @test
     */
    public function test_scan_outside_school_area_rejected()
    {
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now(),
            'valid_until' => now()->addMinutes(10),
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        // Scan from far away location (> 100m radius)
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'token' => $token,
                'latitude' => -6.300000, // ~11km away
                'longitude' => 106.900000,
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Location out of range',
            ]);

        // Verify NO attendance created
        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);
    }

    /**
     * TEST 5: Manual Attendance with "present" Status Rejected
     *
     * @test
     */
    public function test_manual_attendance_present_status_rejected()
    {
        // Try to create manual attendance with "present" status
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/v1/attendance/manual', [
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today()->toDateString(),
                'status' => 'present', // NOT ALLOWED
                'notes' => 'Test note',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'status' => ['Status manual hanya boleh: sakit, izin, alpa, atau excused. Untuk hadir gunakan scan QR.'],
            ]);

        // Verify NO attendance created
        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->student->id,
            'status' => 'present',
            'is_manual' => true,
        ]);
    }

    /**
     * BONUS TEST: Manual Attendance with Valid Status (sick) Success
     *
     * @test
     */
    public function test_manual_attendance_sick_status_success()
    {
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->postJson('/api/v1/attendance/manual', [
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today()->toDateString(),
                'status' => 'sick', // ALLOWED
                'notes' => 'Demam tinggi',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Absensi manual berhasil disimpan',
            ]);

        // Verify attendance created
        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'status' => 'sick',
            'is_manual' => true,
            'recorded_by' => $this->teacher->id,
        ]);
    }
}
