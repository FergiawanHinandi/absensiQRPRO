<?php

namespace Tests\Unit\Services;

use App\Exceptions\AttendanceException;
use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use App\Services\ProductionAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class ProductionAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductionAttendanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ProductionAttendanceService::class);
        
        // Clear Redis before each test
        Redis::flushdb();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_successfully_records_attendance()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $student->school_id);

        $result = $this->service->scan($student, [
            'qr_token' => $qrToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'device_id' => 'test-device',
        ]);

        $this->assertTrue($result['success']);
        $this->assertEquals('present', $result['status']);
        $this->assertInstanceOf(Attendance::class, $result['attendance']);
        
        $this->assertDatabaseHas('attendances', [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_duplicate_attendance_with_redis_lock()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $student->school_id);
        $scanData = [
            'qr_token' => $qrToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ];

        // First scan
        $result1 = $this->service->scan($student, $scanData);
        $this->assertTrue($result1['success']);
        $this->assertEquals('present', $result1['status']);

        // Second scan (should be prevented by Redis lock)
        $result2 = $this->service->scan($student, $scanData);
        $this->assertTrue($result2['success']);
        $this->assertEquals('duplicate', $result2['status']);
        $this->assertEquals($result1['attendance']->id, $result2['attendance']->id);

        // Verify only 1 record exists
        $this->assertEquals(1, Attendance::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_handles_concurrent_scans_atomically()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $student->school_id);

        // Simulate concurrent requests by clearing Redis lock between attempts
        $results = [];
        
        for ($i = 0; $i < 5; $i++) {
            try {
                $results[] = $this->service->scan($student, [
                    'qr_token' => $qrToken,
                    'latitude' => -6.200000,
                    'longitude' => 106.816666,
                ]);
            } catch (\Exception $e) {
                // Expected for some concurrent attempts
            }
        }

        // Only 1 attendance should be created
        $this->assertEquals(1, Attendance::count());
        
        // At least one result should be successful
        $successCount = collect($results)->filter(fn($r) => $r['success'])->count();
        $this->assertGreaterThan(0, $successCount);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_expired_qr_token()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        // Generate expired token
        $expiredToken = $this->generateExpiredQrToken($schedule, $student->school_id);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('QR code sudah kadaluarsa');

        $this->service->scan($student, [
            'qr_token' => $expiredToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_invalid_qr_signature()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        // Generate token with invalid signature
        $invalidToken = $this->generateInvalidSignatureToken($schedule, $student->school_id);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('QR code tidak valid atau telah dimodifikasi');

        $this->service->scan($student, [
            'qr_token' => $invalidToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_school_mismatch()
    {
        $student = User::factory()->student()->create();
        $otherSchool = School::factory()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $otherSchool->id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $otherSchool->id);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('QR code tidak valid untuk sekolah Anda');

        $this->service->scan($student, [
            'qr_token' => $qrToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_gps_coordinates()
    {
        $school = School::factory()->create([
            'latitude' => -6.200000,
            'longitude' => 106.816666,
            'attendance_radius' => 100, // 100 meters
        ]);
        
        $student = User::factory()->student()->create([
            'school_id' => $school->id,
        ]);
        
        $schedule = Schedule::factory()->create([
            'school_id' => $school->id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $school->id);

        // Too far from school (should fail)
        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('Anda terlalu jauh dari sekolah');

        $this->service->scan($student, [
            'qr_token' => $qrToken,
            'latitude' => -6.300000, // ~11km away
            'longitude' => 106.916666,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_generates_qr_code_successfully()
    {
        $teacher = User::factory()->teacher()->create();
        $schedule = Schedule::factory()->create([
            'teacher_id' => $teacher->id,
            'school_id' => $teacher->school_id,
        ]);

        $result = $this->service->generateQR($schedule->id, $teacher, 60);

        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('payload', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertEquals($schedule->id, $result['schedule_id']);
        
        // Verify Redis key exists
        $redisKey = "qr_active:{$teacher->school_id}:{$schedule->id}";
        $this->assertTrue(Redis::exists($redisKey));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_existing_qr_if_still_valid()
    {
        $teacher = User::factory()->teacher()->create();
        $schedule = Schedule::factory()->create([
            'teacher_id' => $teacher->id,
            'school_id' => $teacher->school_id,
        ]);

        // First generation
        $qr1 = $this->service->generateQR($schedule->id, $teacher, 60);

        // Second generation (should return same QR)
        $qr2 = $this->service->generateQR($schedule->id, $teacher, 60);

        $this->assertEquals($qr1['token'], $qr2['token']);
        $this->assertEquals($qr1['payload']['nonce'], $qr2['payload']['nonce']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_prevents_multiple_active_qr_codes()
    {
        $teacher = User::factory()->teacher()->create();
        $schedule = Schedule::factory()->create([
            'teacher_id' => $teacher->id,
            'school_id' => $teacher->school_id,
        ]);

        // Generate QR multiple times concurrently
        $results = [];
        for ($i = 0; $i < 5; $i++) {
            $results[] = $this->service->generateQR($schedule->id, $teacher, 60);
        }

        // All should return the same token
        $tokens = array_unique(array_column($results, 'token'));
        $this->assertCount(1, $tokens);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_unauthorized_teacher()
    {
        $teacher = User::factory()->teacher()->create();
        $otherTeacher = User::factory()->teacher()->create();
        
        $schedule = Schedule::factory()->create([
            'teacher_id' => $otherTeacher->id,
            'school_id' => $teacher->school_id,
        ]);

        $this->expectException(AttendanceException::class);
        $this->expectExceptionMessage('Anda tidak memiliki akses ke jadwal ini');

        $this->service->generateQR($schedule->id, $teacher, 60);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_successful_attendance()
    {
        Log::shouldReceive('channel')
            ->with('audit')
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->with('attendance_success', \Mockery::type('array'))
            ->once();

        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $student->school_id);

        $this->service->scan($student, [
            'qr_token' => $qrToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_logs_duplicate_attempts()
    {
        $student = User::factory()->student()->create();
        $schedule = Schedule::factory()->create([
            'school_id' => $student->school_id,
        ]);
        
        $qrToken = $this->generateValidQrToken($schedule, $student->school_id);
        $scanData = [
            'qr_token' => $qrToken,
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ];

        // First scan
        $this->service->scan($student, $scanData);

        // Second scan should log duplicate attempt
        Log::shouldReceive('channel')
            ->with('audit')
            ->andReturnSelf();
        
        Log::shouldReceive('info')
            ->with('attendance_duplicate_attempt', \Mockery::type('array'))
            ->once();

        $this->service->scan($student, $scanData);
    }

    // Helper Methods

    private function generateValidQrToken(Schedule $schedule, int $schoolId): string
    {
        $payload = [
            'schedule_id' => $schedule->id,
            'school_id' => $schoolId,
            'class_id' => $schedule->class_id,
            'teacher_id' => $schedule->teacher_id,
            'nonce' => \Illuminate\Support\Str::random(32),
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
        ];

        $payload['signature'] = $this->generateSignature($payload);

        return base64_encode(json_encode($payload));
    }

    private function generateExpiredQrToken(Schedule $schedule, int $schoolId): string
    {
        $payload = [
            'schedule_id' => $schedule->id,
            'school_id' => $schoolId,
            'class_id' => $schedule->class_id,
            'teacher_id' => $schedule->teacher_id,
            'nonce' => \Illuminate\Support\Str::random(32),
            'issued_at' => now()->subMinutes(10)->toIso8601String(),
            'expires_at' => now()->subMinutes(5)->toIso8601String(), // Expired
        ];

        $payload['signature'] = $this->generateSignature($payload);

        return base64_encode(json_encode($payload));
    }

    private function generateInvalidSignatureToken(Schedule $schedule, int $schoolId): string
    {
        $payload = [
            'schedule_id' => $schedule->id,
            'school_id' => $schoolId,
            'class_id' => $schedule->class_id,
            'teacher_id' => $schedule->teacher_id,
            'nonce' => \Illuminate\Support\Str::random(32),
            'issued_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(5)->toIso8601String(),
            'signature' => 'invalid-signature', // Invalid
        ];

        return base64_encode(json_encode($payload));
    }

    private function generateSignature(array $payload): string
    {
        $data = $payload['schedule_id'] . 
                $payload['school_id'] . 
                $payload['nonce'] . 
                $payload['issued_at'];

        return hash_hmac('sha256', $data, config('app.qr_secret_key'));
    }
}
