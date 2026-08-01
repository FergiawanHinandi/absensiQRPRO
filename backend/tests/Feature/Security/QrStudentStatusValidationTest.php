<?php

namespace Tests\Feature\Security;

use App\Models\School;
use App\Models\User;
use App\Services\StudentQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class QrStudentStatusValidationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $student;

    protected StudentQrService $qrService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::create([
            'name' => 'Test School',
            'school_level' => 'SMA',
            'npsn' => '12345678',
            'phone' => '08123456789',
            'email' => 'test@school.com',
            'address' => 'Test Address',
            'is_active' => true,
        ]);

        // Create active student
        $this->student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student001',
            'name' => 'Test Student',
            'email' => 'student@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->qrService = app(StudentQrService::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function active_student_with_valid_qr_passes_validation()
    {
        // Generate valid QR token
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $this->school->id
        );

        // Verify HMAC
        $payload = $this->qrService->verify($qrToken);

        // Validate student status
        $validatedStudent = $this->qrService->validateStudentStatus($payload, $this->school->id);

        $this->assertNotNull($validatedStudent);
        $this->assertEquals($this->student->id, $validatedStudent->id);
        $this->assertTrue($validatedStudent->is_active);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function inactive_student_with_valid_hmac_fails_validation()
    {
        // Deactivate student
        $this->student->update(['is_active' => false]);

        // Generate valid QR token (signature still valid)
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $this->school->id
        );

        // Verify HMAC passes
        $payload = $this->qrService->verify($qrToken);

        // But status validation should fail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak aktif');

        $this->qrService->validateStudentStatus($payload, $this->school->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function nonexistent_student_with_valid_hmac_fails_validation()
    {
        $fakeStudentId = 99999;

        // Manually create valid HMAC payload for non-existent student
        $payload = [
            'sid' => $fakeStudentId,
            'sch' => $this->school->id,
            'typ' => 'student_card',
            'iat' => now()->timestamp,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));
        $qrToken = $encoded.'.'.$signature;

        // Verify HMAC passes
        $verifiedPayload = $this->qrService->verify($qrToken);

        // But status validation should fail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak ditemukan');

        $this->qrService->validateStudentStatus($verifiedPayload, $this->school->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_from_different_school_fails_validation()
    {
        // Create second school
        $school2 = School::create([
            'name' => 'Test School 2',
            'school_level' => 'SMA',
            'npsn' => '87654321',
            'phone' => '08987654321',
            'email' => 'test2@school.com',
            'address' => 'Test Address 2',
            'is_active' => true,
        ]);

        // Generate QR with school 2 ID but try to use at school 1
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $school2->id // Different school
        );

        // Verify HMAC passes
        $payload = $this->qrService->verify($qrToken);

        // But validation should fail - QR school doesn't match student's school
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak sesuai dengan data sekolah siswa');

        $this->qrService->validateStudentStatus($payload, $this->school->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_mismatch_between_student_and_qr_fails_validation()
    {
        // Create second school
        $school2 = School::create([
            'name' => 'Test School 2',
            'school_level' => 'SMA',
            'npsn' => '87654321',
            'phone' => '08987654321',
            'email' => 'test2@school.com',
            'address' => 'Test Address 2',
            'is_active' => true,
        ]);

        // Generate QR with school 2 but student belongs to school 1
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $school2->id
        );

        // Verify HMAC passes
        $payload = $this->qrService->verify($qrToken);

        // Validation should fail - student's school doesn't match QR school
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('tidak sesuai dengan data sekolah siswa');

        $this->qrService->validateStudentStatus($payload, $school2->id);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function invalid_student_with_valid_hmac_logs_security_anomaly()
    {
        // Mock the security log channel
        $securityChannel = Mockery::mock();
        $securityChannel->shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'QR Security Anomaly') &&
                       str_contains($message, 'inactive_student_attempted_scan');
            });

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($securityChannel);

        // Deactivate student
        $this->student->update(['is_active' => false]);

        // Generate valid QR token
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $this->school->id
        );

        // Verify HMAC passes
        $payload = $this->qrService->verify($qrToken);

        // Try validation (will fail)
        try {
            $this->qrService->validateStudentStatus($payload, $this->school->id);
        } catch (\Exception $e) {
            // Expected
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function nonexistent_student_logs_critical_anomaly()
    {
        // Mock the security log channel
        $securityChannel = Mockery::mock();
        $securityChannel->shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'student_not_found_after_hmac') &&
                       isset($context['student_id']) &&
                       $context['student_id'] === 99999;
            });

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($securityChannel);

        $fakeStudentId = 99999;

        // Create valid HMAC for non-existent student
        $payload = [
            'sid' => $fakeStudentId,
            'sch' => $this->school->id,
            'typ' => 'student_card',
            'iat' => now()->timestamp,
        ];

        $encoded = base64_encode(json_encode($payload));
        $signature = hash_hmac('sha256', $encoded, config('qr.secret'));
        $qrToken = $encoded.'.'.$signature;

        // Verify HMAC passes
        $verifiedPayload = $this->qrService->verify($qrToken);

        // Try validation (will fail)
        try {
            $this->qrService->validateStudentStatus($verifiedPayload, $this->school->id);
        } catch (\Exception $e) {
            // Expected
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cross_school_attempt_logs_critical_severity()
    {
        // Mock the security log channel
        $securityChannel = Mockery::mock();
        $securityChannel->shouldReceive('warning')
            ->once()
            ->withArgs(function ($message, $context) {
                return str_contains($message, 'QR Security Anomaly') &&
                       isset($context['severity']) &&
                       $context['severity'] === 'HIGH'; // This will log school_mismatch_in_qr first
            });

        Log::shouldReceive('channel')
            ->with('security')
            ->andReturn($securityChannel);

        // Create second school
        $school2 = School::create([
            'name' => 'Test School 2',
            'school_level' => 'SMA',
            'npsn' => '87654321',
            'phone' => '08987654321',
            'email' => 'test2@school.com',
            'address' => 'Test Address 2',
            'is_active' => true,
        ]);

        // Generate QR with school 2 ID
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $school2->id
        );

        // Verify HMAC passes
        $payload = $this->qrService->verify($qrToken);

        // Try validation at school 1 (will fail)
        try {
            $this->qrService->validateStudentStatus($payload, $this->school->id);
        } catch (\Exception $e) {
            // Expected
        }
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function audit_log_created_for_security_anomaly()
    {
        // Deactivate student
        $this->student->update(['is_active' => false]);

        // Generate valid QR token
        $qrToken = $this->qrService->generateStudentCard(
            $this->student->id,
            $this->school->id
        );

        // Verify HMAC passes
        $payload = $this->qrService->verify($qrToken);

        // Try validation (will fail)
        try {
            $this->qrService->validateStudentStatus($payload, $this->school->id);
        } catch (\Exception $e) {
            // Expected
        }

        // Verify audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->student->id,
            'action' => 'qr_security_anomaly',
        ]);
    }
}
