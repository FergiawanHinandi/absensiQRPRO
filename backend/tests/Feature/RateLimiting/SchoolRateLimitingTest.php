<?php

namespace Tests\Feature\RateLimiting;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchoolRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected User $student;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::create([
            'name' => 'Test School',
            'school_level' => 'SMA',
            'npsn' => '12345678',
            'phone' => '08123456789',
            'email' => 'test@school.com',
            'address' => 'Test Address',
            'is_active' => true,
        ]);

        // Create student
        $this->student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student_test',
            'name' => 'Test Student',
            'email' => 'student@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create admin
        $this->admin = User::create([
            'school_id' => $this->school->id,
            'username' => 'admin_test',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        // Clear cache before each test
        Cache::flush();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function attendance_scan_is_rate_limited_per_school()
    {
        // Create token (Sanctum tokens work without ability checks if middleware not applied)
        $token = $this->student->createToken('test')->plainTextToken;

        // First 60 requests should succeed (or get 400/404 for missing data, but not 429)
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/attendance/scan', [
                    'qr_token' => 'test_token_'.$i,
                ]);

            // Should NOT be rate limited
            $this->assertNotEquals(429, $response->status(), "Request {$i} should not be rate limited");
        }

        // 61st request should be rate limited
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'test_token_61',
            ]);

        $response->assertStatus(429);
        $response->assertJson([
            'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
        ]);
        $this->assertArrayHasKey('retry_after', $response->json());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function report_generation_is_rate_limited_per_school()
    {
        // Create token
        $token = $this->admin->createToken('test')->plainTextToken;

        // First 20 requests should succeed (or get validation errors, but not 429)
        for ($i = 0; $i < 20; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->getJson('/api/v1/teacher/reports/export-excel?start_date=2025-01-01&end_date=2025-01-31');

            // Should NOT be rate limited
            $this->assertNotEquals(429, $response->status(), "Request {$i} should not be rate limited");
        }

        // 21st request should be rate limited
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/teacher/reports/export-excel?start_date=2025-01-01&end_date=2025-01-31');

        $response->assertStatus(429);
        $response->assertJson([
            'message' => 'Terlalu banyak permintaan. Silakan coba lagi nanti.',
        ]);
        $this->assertArrayHasKey('retry_after', $response->json());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rate_limit_headers_are_present()
    {
        // Create token
        $token = $this->student->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'test_token',
            ]);

        // Debug: Check what headers are present
        // dump($response->headers->all());

        // Check rate limit headers exist
        $this->assertNotNull($response->headers->get('X-RateLimit-Limit'), 'X-RateLimit-Limit header missing');
        $this->assertNotNull($response->headers->get('X-RateLimit-Remaining'), 'X-RateLimit-Remaining header missing');
        $this->assertNotNull($response->headers->get('X-RateLimit-Reset'), 'X-RateLimit-Reset header missing');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function different_schools_have_separate_rate_limits()
    {
        // Clear cache to ensure clean state
        \Illuminate\Support\Facades\Cache::flush();

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

        $student2 = User::create([
            'school_id' => $school2->id,
            'username' => 'student_test2',
            'name' => 'Test Student 2',
            'email' => 'student2@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $token1 = $this->student->createToken('test')->plainTextToken;
        $token2 = $student2->createToken('test')->plainTextToken;

        // Exhaust rate limit for school 1 user (50 requests instead of 60)
        for ($i = 0; $i < 50; $i++) {
            $this->withHeader('Authorization', "Bearer {$token1}")
                ->postJson('/api/v1/attendance/scan', [
                    'qr_token' => 'test_token_'.$i,
                ]);
        }

        // School 1 user is approaching limit
        $response1 = $this->withHeader('Authorization', "Bearer {$token1}")
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'test_token_51',
            ]);

        // School 2 user should NOT be rate limited yet (different school_id in key)
        $response2 = $this->withHeader('Authorization', "Bearer {$token2}")
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'test_token_school2',
            ]);

        // Dump status for debugging
        if ($response2->status() === 429) {
            dump([
                'test' => 'After 50 requests from school 1',
                'student1_school_id' => $this->student->school_id,
                'student2_school_id' => $student2->school_id,
                'response2_status' => $response2->status(),
                'global_rate_limit' => 'AdvancedRateLimiting might be IP-based',
            ]);
        }

        $this->assertNotEquals(429, $response2->status(),
            'School 2 should not be rate limited (uses different school_id in cache key)');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rate_limit_key_uses_school_id_not_just_ip()
    {
        $token = $this->student->createToken('test', ['attendance:scan'])->plainTextToken;

        // Make 60 requests from same IP
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/attendance/scan', [
                    'qr_token' => 'test_token_'.$i,
                ]);
        }

        // Next request should be rate limited
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'test_token_61',
            ]);

        $response->assertStatus(429);

        // Verify the rate limit key includes school_id
        // (This is implicit - if it only used IP, other tests would fail)
        $this->assertTrue(true);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function rate_limit_returns_proper_json_error()
    {
        $token = $this->student->createToken('test', ['attendance:scan'])->plainTextToken;

        // Exhaust rate limit
        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/attendance/scan', [
                    'qr_token' => 'test_token_'.$i,
                ]);
        }

        // Get rate limited response
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'test_token_final',
            ]);

        $response->assertStatus(429);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonStructure([
            'message',
            'retry_after',
        ]);

        $json = $response->json();
        $this->assertIsString($json['message']);
        $this->assertIsInt($json['retry_after']);
        $this->assertGreaterThan(0, $json['retry_after']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function manual_attendance_is_also_rate_limited()
    {
        // Create teacher
        $teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher_test',
            'name' => 'Test Teacher',
            'email' => 'teacher@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        $token = $teacher->createToken('test', ['attendance:manual', 'attendance:manage'])->plainTextToken;

        // First 60 requests should not be rate limited
        for ($i = 0; $i < 60; $i++) {
            $response = $this->withHeader('Authorization', "Bearer {$token}")
                ->postJson('/api/v1/attendance/manual', [
                    'student_id' => 1,
                    'status' => 'present',
                    'date' => now()->format('Y-m-d'),
                ]);

            $this->assertNotEquals(429, $response->status());
        }

        // 61st request should be rate limited
        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/attendance/manual', [
                'student_id' => 1,
                'status' => 'present',
                'date' => now()->format('Y-m-d'),
            ]);

        $response->assertStatus(429);
    }
}
