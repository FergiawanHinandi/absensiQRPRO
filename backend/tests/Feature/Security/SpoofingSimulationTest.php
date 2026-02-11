<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\School;
use App\Models\TeacherDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Spoofing & Brute Force Simulation
 * 
 * Uji ketahanan terhadap:
 * 1. Device Spoofing (Unregistered Device ID)
 * 2. GPS Spoofing (Mock Location / Bad Accuracy)
 * 3. Replay Attack (Idempotency Reuse)
 * 4. Brute Force Login
 * 
 * @group security
 * @group spoofing
 */
class SpoofingSimulationTest extends TestCase
{
    use RefreshDatabase;

    protected $school;
    protected $teacher;
    protected $student;
    protected $registeredDeviceId = 'valid-device-uuid-123';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->school = School::factory()->create();
        
        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'email' => 'teacher@school.com',
            'password' => Hash::make('password'),
        ]);

        // Register valid device for teacher
        TeacherDevice::create([
            'user_id' => $this->teacher->id,
            'device_id' => $this->registeredDeviceId,
            'device_name' => 'Test Phone',
            'model' => 'Pixel 5',
            'is_verified' => true,
            'last_active_at' => now(),
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);
    }

    /**
     * TEST 1: Device ID Spoofing
     * Teacher mencoba scan menggunakan device ID yang tidak terdaftar.
     */
    public function test_rejects_unregistered_device_id()
    {
        $spoofedDeviceId = 'fake-device-999';

        $response = $this->actingAs($this->teacher)
            ->withHeaders([
                'X-Device-ID' => $spoofedDeviceId, // Spoofed header
                'User-Agent' => 'okhttp/4.9.0'
            ])
            ->postJson('/api/v1/attendance/scan-student', [
                // Payload scan valid
                'qr_token' => 'some-valid-token', 
                'lat' => -6.200000,
                'lng' => 106.816666
            ]);

        // Expectation: 403 Forbidden (Device Warning/Block)
        // Middleware CheckTeacherDevice harus menangkap ini
        $response->assertStatus(403);
        $response->assertJsonFragment(['message' => 'Perangkat tidak dikenali. Silakan verifikasi ulang.']);
        
        // Log Verification
        // Implementation detail: check if log was written (optional assertion via mocking Log facade)
    }

    /**
     * TEST 2: GPS Spoofing (Mock Location)
     * Student mengirim data scan dengan flag mock/accuracy mencurigakan.
     */
    public function test_rejects_mock_gps_location()
    {
        // Student Scan Endpoint
        $response = $this->actingAs($this->student)
            ->postJson('/api/v1/attendance/scan', [
                'qr_token' => 'valid-token',
                'latitude' => -6.200000,
                'longitude' => 106.816666,
                'accuracy' => 0.5, // Too perfect (likely PC/Emulator)
                'is_mocked' => true, // Explicit flag from OS
                'device_fingerprint' => 'device-123',
                'client_timestamp' => now()->timestamp,
            ]);
            
        // Expectation: 400 Bad Request or 422 Unprocessable Entity
        // Service logic should reject mocked locations
        // Or return success but mark as 'Rejected' status in DB
        
        // If system rejects immediately:
        $response->assertStatus(400); 
        $response->assertJsonFragment(['message' => 'Lokasi palsu terdeteksi']);
    }

    /**
     * TEST 3: Replay Attack (Idempotency)
     * Mengirim payload valid yang sama persis berulang kali.
     */
    public function test_rejects_replay_attack()
    {
        $key = Str::uuid()->toString();
        $payload = [
            'student_id' => $this->student->id,
            'status' => 'present',
            'attendance_date' => now()->toDateString()
        ];

        // 1. First Request
        $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $key)
            ->withHeader('X-Device-ID', $this->registeredDeviceId) // Valid device
            ->postJson('/api/v1/attendance/manual', $payload)
            ->assertStatus(201); // Created

        // 2. Replay Request (Same Key)
        $response = $this->actingAs($this->teacher)
            ->withHeader('X-Idempotency-Key', $key) // Same Key
            ->withHeader('X-Device-ID', $this->registeredDeviceId)
            ->postJson('/api/v1/attendance/manual', $payload);

        // Expectation: 409 Conflict
        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'Duplicate request detected']);
    }

    /**
     * TEST 4: Brute Force Login
     * Mencoba login 10x dalam waktu singkat.
     */
    public function test_rate_limits_brute_force_login()
    {
        $email = $this->teacher->email;
        
        // 5 Allowed attempts (default typical limit)
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $email,
                'password' => 'wrong-password'
            ])->assertStatus(422); // Validation error (password mismatch)
        }

        // 6th Attempt - Should be blocked
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'wrong-password'
        ]);

        // Expectation: 429 Too Many Requests
        $response->assertStatus(429);
        
        // Ensure no data change (Teacher password still valid)
        $this->assertTrue(Hash::check('password', $this->teacher->fresh()->password));
        
        // Ensure Lockout Log exists (via database check usually)
        // $this->assertDatabaseHas('audit_logs', ['action' => 'login_lockout']); 
    }
}
