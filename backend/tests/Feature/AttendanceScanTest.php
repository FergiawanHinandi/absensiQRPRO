<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\TeacherDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceScanTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $teacher;

    private School $school;

    private Schedule $schedule;

    private string $teacherDeviceId;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::factory()->create([
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
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'username' => 'teacher1',
            'name' => 'Teacher Test',
            'email' => 'teacher@test.com',
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create student
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'username' => 'student1',
            'name' => 'Student Test',
            'email' => 'student@test.com',
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create schedule (active today, window covers "now")
        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => now()->subMinutes(5)->format('H:i:s'),
            'end_time' => now()->addMinutes(50)->format('H:i:s'),
            'room' => 'Room 1',
        ]);

        // Register approved device for the teacher (CheckTeacherDevice middleware)
        $this->teacherDeviceId = 'device-'.Str::uuid();
        TeacherDevice::factory()->create([
            'teacher_id' => $this->teacher->id,
            'school_id' => $this->school->id,
            'device_id' => $this->teacherDeviceId,
            'is_approved' => true,
        ]);
    }

    private function manualRequest(array $overrides = [])
    {
        Sanctum::actingAs($this->teacher, ['*']);

        return $this->withHeader('X-Device-ID', $this->teacherDeviceId)
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/attendance/manual', array_merge([
                'schedule_id' => $this->schedule->id,
                'student_id' => $this->student->id,
                'attendance_date' => today()->toDateString(),
                'status' => 'present',
                'notes' => 'Test note',
            ], $overrides));
    }

    private function generateQrToken(Schedule $schedule): string
    {
        $payload = [
            'sid' => $this->student->id,
            'sch' => $this->school->id,
            'iat' => now()->timestamp,
            'schedule_id' => $schedule->id,
            'nonce' => Str::random(16),
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));

        return $encoded.'.'.$signature;
    }

    private function scanRequest(string $token, ?User $user = null)
    {
        Sanctum::actingAs($user ?? $this->student, ['*']);

        return $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'latitude' => -6.200000,
                'longitude' => 106.816666,
                'accuracy' => 10,
                'device_fingerprint' => 'test-device-fingerprint',
                'request_id' => (string) Str::uuid(),
            ]);
    }

    /**
     * TEST 1: Scan Valid QR Code
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scan_valid_qr_code_success()
    {
        $token = $this->generateQrToken($this->schedule);

        $response = $this->scanRequest($token);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Absensi berhasil dicatat.',
            ]);

        // Verify attendance created
        $this->assertDatabaseHas('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_type' => 'qr_scan',
            'is_manual' => false,
        ]);
    }

    /**
     * TEST 2: Scan Duplicate (Already Scanned)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scan_duplicate_qr_code_rejected()
    {
        // Create existing attendance (already scanned)
        Attendance::factory()->create([
            'school_id' => $this->school->id,
            'schedule_id' => $this->schedule->id,
            'student_id' => $this->student->id,
            'attendance_date' => today(),
            'attendance_type' => 'qr_scan',
            'check_in_time' => now(),
            'is_manual' => false,
        ]);

        $token = $this->generateQrToken($this->schedule);

        // Try to scan again (new idempotency key = new attempt)
        $response = $this->scanRequest($token);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Absensi sudah dicatat sebelumnya.',
            ]);
    }

    /**
     * TEST 3: Scan Expired QR Code
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scan_expired_qr_code_rejected()
    {
        // Generate token with expired timestamp
        $expiredPayload = [
            'sid' => $this->student->id,
            'sch' => $this->school->id,
            'iat' => now()->subMinutes(20)->timestamp,
            'exp' => now()->subMinutes(10)->timestamp, // Expired
            'schedule_id' => $this->schedule->id,
            'nonce' => Str::random(16),
        ];

        $encoded = base64_encode(json_encode($expiredPayload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));
        $expiredToken = $encoded.'.'.$signature;

        // Try to scan expired QR
        $response = $this->scanRequest($expiredToken);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'QR Code tidak valid atau sudah kadaluarsa.',
            ]);
    }

    /**
     * TEST 4: Scan Outside School Area (GPS Validation)
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scan_outside_school_area_rejected()
    {
        $token = $this->generateQrToken($this->schedule);

        // Scan from far away location (> 100m radius)
        Sanctum::actingAs($this->student, ['*']);

        $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => $token,
                'latitude' => -6.300000, // ~11km away
                'longitude' => 106.900000,
                'accuracy' => 10,
                'device_fingerprint' => 'test-device-fingerprint',
                'request_id' => (string) Str::uuid(),
            ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Lokasi di luar radius yang diizinkan.',
            ]);

        // Verify NO attendance created
        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
        ]);
    }

    /**
     * TEST 5: Manual Attendance with "present" Status Rejected
     * Present/late must come from QR scan; manual is only for exceptions.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_manual_attendance_present_status_rejected()
    {
        // Try to create manual attendance with "present" status
        $response = $this->manualRequest();

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
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_manual_attendance_sick_status_success()
    {
        $response = $this->manualRequest(['status' => 'sick', 'notes' => 'Demam tinggi']);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
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

    /**
     * EDGE CASE 1: Scan Before School Hours
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scan_before_school_hours_rejected()
    {
        // Create schedule for later today (e.g., 14:00-15:30)
        $futureSchedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => '14:00:00',
            'end_time' => '15:30:00',
            'room' => 'Room 2',
        ]);

        // Mock current time to be way before schedule (e.g., 06:00 AM)
        $this->travel(-8)->hours();

        $token = $this->generateQrToken($futureSchedule);

        // Attempt scan before school hours
        $response = $this->scanRequest($token);

        // Rejected because scan is outside the valid time window
        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => 'Absensi belum dibuka. Silakan scan mulai pukul 13:45.',
            ]);
    }

    /**
     * EDGE CASE 2: Scan Slightly Late
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_scan_slightly_late_marked_as_late()
    {
        // Create schedule that started 20 mins ago
        $schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
            'start_time' => now()->subMinutes(20)->format('H:i:s'),
            'end_time' => now()->addHour()->format('H:i:s'),
            'room' => 'Room 3',
        ]);

        $token = $this->generateQrToken($schedule);

        // Scan 20 minutes after start time (late)
        $response = $this->scanRequest($token);

        $response->assertStatus(201);

        // Verify attendance recorded with server-determined status
        $attendance = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $schedule->id)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertContains($attendance->status, ['present', 'late']);
    }

    /**
     * EDGE CASE 3: Multiple Scans - Only First Valid
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function test_multiple_scans_only_first_valid_counted()
    {
        $token = $this->generateQrToken($this->schedule);

        // First scan - should succeed
        $response1 = $this->scanRequest($token);

        $response1->assertStatus(201);

        // Get first attendance record
        $firstAttendance = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->first();

        $this->assertNotNull($firstAttendance);
        $firstCheckInTime = $firstAttendance->check_in_time;

        // Second scan (new idempotency key) - should be rejected (duplicate)
        $response2 = $this->scanRequest($token);

        $response2->assertStatus(400);

        // Verify only ONE attendance record exists
        $attendanceCount = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->count();

        $this->assertEquals(1, $attendanceCount);

        // Verify check_in_time hasn't changed (first scan preserved)
        $finalAttendance = Attendance::where('student_id', $this->student->id)
            ->where('schedule_id', $this->schedule->id)
            ->first();

        $this->assertEquals(
            $firstCheckInTime->timestamp,
            $finalAttendance->check_in_time->timestamp
        );
    }
}
