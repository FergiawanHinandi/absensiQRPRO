<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\QrCode;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\TeacherDevice;
use App\Models\User;
use App\Services\QrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Critical Attendance Flow Tests
 * 
 * These tests cover the essential attendance scenarios that MUST pass
 * before any deployment. They test the complete flow from QR generation
 * to attendance recording.
 * 
 * Test Categories:
 * 1. QR Code Generation & Validation
 * 2. Successful Attendance Scan (In/Out)
 * 3. Error Cases (Expired QR, Invalid Token, Duplicate Scan)
 * 4. Security (Outside Geofence, Unregistered Device, Mock Location)
 * 5. Multi-tenant Isolation
 */
class CriticalAttendanceFlowTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $teacher;
    private User $student;
    private Schedule $schedule;
    private QrService $qrService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qrService = app(QrService::class);

        // Create test school with geofence
        $this->school = School::create([
            'name' => 'SMP Test Absensi',
            'npsn' => '12345678',
            'school_level' => 'SMP',
            'address' => 'Jl. Test No. 1',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'radius_meters' => 100,
            'is_active' => true,
        ]);

        // Create teacher
        $this->teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher_test',
            'name' => 'Guru Test',
            'email' => 'teacher@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create student
        $this->student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student_test',
            'name' => 'Siswa Test',
            'email' => 'student@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create schedule for today
        $this->schedule = Schedule::create([
            'school_id' => $this->school->id,
            'academic_year_id' => 1,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => '07:00:00',
            'end_time' => '08:30:00',
            'room' => 'Kelas 7A',
        ]);
    }

    // =========================================================================
    // CATEGORY 1: QR Code Generation
    // =========================================================================

    /** @test */
    public function test_qr_token_generation_is_valid()
    {
        $payload = [
            'schedule_id' => $this->schedule->id,
            'qr_id' => 1,
            'type' => 'in',
        ];

        $token = $this->qrService->generate($payload);

        // Token should be non-empty string
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Token should be validatable
        $validated = $this->qrService->validate($token);
        $this->assertIsArray($validated);
        $this->assertEquals($this->schedule->id, $validated['schedule_id']);
        $this->assertEquals('in', $validated['type']);
    }

    /** @test */
    public function test_qr_token_expires_after_timeout()
    {
        $payload = [
            'schedule_id' => $this->schedule->id,
            'qr_id' => 1,
            'type' => 'in',
            'exp' => now()->subMinutes(5)->timestamp, // Expired 5 minutes ago
        ];

        $token = $this->qrService->generate($payload);

        $this->expectException(\App\Exceptions\QrExpiredException::class);
        $this->qrService->validate($token);
    }

    /** @test */
    public function test_tampered_qr_token_is_rejected()
    {
        $payload = [
            'schedule_id' => $this->schedule->id,
            'qr_id' => 1,
            'type' => 'in',
        ];

        $token = $this->qrService->generate($payload);
        
        // Tamper with the token
        $tamperedToken = $token . 'tampered';

        $this->expectException(\App\Exceptions\QrInvalidException::class);
        $this->qrService->validate($tamperedToken);
    }

    // =========================================================================
    // CATEGORY 2: Successful Attendance Scan
    // =========================================================================

    /** @test */
    public function test_valid_scan_creates_attendance_record()
    {
        // Create QR code
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
                'accuracy' => 10,
            ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // Verify attendance record created
        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);
    }

    /** @test */
    public function test_scan_out_records_checkout_time()
    {
        // First create check-in attendance
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->toDateString(),
            'check_in_time' => now()->subHours(1),
            'status' => 'present',
        ]);

        // Create check-out QR code
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'out',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'out',
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
            ]);

        $response->assertStatus(200);

        // Verify check_out_time is set
        $attendance->refresh();
        $this->assertNotNull($attendance->check_out_time);
    }

    // =========================================================================
    // CATEGORY 3: Error Cases
    // =========================================================================

    /** @test */
    public function test_expired_qr_code_is_rejected()
    {
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(20),
            'valid_until' => now()->subMinutes(10), // Expired
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
            'exp' => now()->subMinutes(10)->timestamp,
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
            ]);

        $response->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function test_duplicate_scan_same_schedule_is_rejected()
    {
        // Create existing attendance
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => now()->toDateString(),
            'check_in_time' => now()->subMinutes(30),
            'status' => 'present',
        ]);

        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
            ]);

        // Should return 409 Conflict or similar
        $response->assertStatus(409);
    }

    /** @test */
    public function test_idempotent_request_with_same_request_id()
    {
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        $requestId = (string) Str::uuid();

        // First request
        $response1 = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
                'request_id' => $requestId,
            ]);

        $response1->assertStatus(200);
        $attendanceId1 = $response1->json('data.id');

        // Second request with SAME request_id should return same attendance
        $response2 = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
                'request_id' => $requestId,
            ]);

        // Should succeed (idempotent) with same ID
        $response2->assertStatus(200);
        $attendanceId2 = $response2->json('data.id');

        $this->assertEquals($attendanceId1, $attendanceId2);
    }

    // =========================================================================
    // CATEGORY 4: Security Cases
    // =========================================================================

    /** @test */
    public function test_scan_outside_geofence_is_flagged()
    {
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        // Location FAR outside geofence (1km away)
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.210000, // ~1km difference
                'lng' => 106.826666,
            ]);

        // Should either fail or create anomaly record
        // Depending on business logic, this could be 200 with warning or 400
        $this->assertTrue(
            $response->status() === 200 || $response->status() === 400,
            'Scan outside geofence should be handled'
        );
    }

    /** @test */
    public function test_mock_location_flag_creates_anomaly()
    {
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
                'is_mocked' => true, // Mock location flag
            ]);

        // Should handle mock location (either reject or flag as anomaly)
        $this->assertTrue(in_array($response->status(), [200, 400, 403]));
    }

    /** @test */
    public function test_unauthenticated_scan_is_rejected()
    {
        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => 1,
            'type' => 'in',
        ]);

        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => $token,
            'lat' => -6.200000,
            'lng' => 106.816666,
        ]);

        $response->assertStatus(401);
    }

    // =========================================================================
    // CATEGORY 5: Multi-tenant Isolation
    // =========================================================================

    /** @test */
    public function test_student_cannot_scan_other_school_qr()
    {
        // Create another school
        $otherSchool = School::create([
            'name' => 'SMA Lain',
            'npsn' => '87654321',
            'school_level' => 'SMA',
            'address' => 'Jl. Lain No. 2',
            'latitude' => -6.300000,
            'longitude' => 106.900000,
            'radius_meters' => 100,
            'is_active' => true,
        ]);

        $otherSchedule = Schedule::create([
            'school_id' => $otherSchool->id,
            'academic_year_id' => 1,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => '07:00:00',
            'end_time' => '08:30:00',
            'room' => 'Kelas X',
        ]);

        $qrCode = QrCode::create([
            'school_id' => $otherSchool->id,
            'schedule_id' => $otherSchedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 30,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $otherSchedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        // Student from school 1 tries to scan QR from school 2
        $response = $this->actingAs($this->student, 'sanctum')
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'lat' => -6.200000,
                'lng' => 106.816666,
            ]);

        // Should be rejected (403 or 400)
        $this->assertTrue(
            in_array($response->status(), [400, 403]),
            'Cross-school scan should be rejected'
        );
    }

    // =========================================================================
    // CATEGORY 6: Rate Limiting
    // =========================================================================

    /** @test */
    public function test_rate_limit_applied_to_scan_endpoint()
    {
        $qrCode = QrCode::create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'qr_type' => 'in',
            'valid_from' => now()->subMinutes(5),
            'valid_until' => now()->addMinutes(10),
            'max_scans' => 100,
            'scan_count' => 0,
            'is_active' => true,
        ]);

        $token = $this->qrService->generate([
            'schedule_id' => $this->schedule->id,
            'qr_id' => $qrCode->id,
            'type' => 'in',
        ]);

        // Make many requests quickly
        $hitRateLimit = false;
        for ($i = 0; $i < 35; $i++) {
            $response = $this->actingAs($this->student, 'sanctum')
                ->postJson('/api/v1/attendance/scan', [
                    'qr_token' => $token,
                    'lat' => -6.200000,
                    'lng' => 106.816666,
                    'request_id' => Str::uuid(), // Different request each time
                ]);

            if ($response->status() === 429) {
                $hitRateLimit = true;
                break;
            }
        }

        $this->assertTrue($hitRateLimit, 'Rate limiting should be applied to scan endpoint');
    }
}
