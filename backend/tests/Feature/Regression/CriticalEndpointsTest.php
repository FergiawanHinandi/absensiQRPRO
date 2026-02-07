<?php

namespace Tests\Feature\Regression;

use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression Tests - Ensure critical endpoints remain stable
 *
 * These tests verify that existing functionality continues to work
 * after code changes, maintaining backward compatibility.
 */
class CriticalEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup test data
        $this->school = School::factory()->create();

        $this->admin = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'school_admin',
            'email' => 'admin@test.local',
            'password' => bcrypt('password'),
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'email' => 'student@test.local',
            'password' => bcrypt('password'),
        ]);
    }

    // =========================================================================
    // REGRESSION: Authentication
    // =========================================================================

    /** @test */
    public function login_endpoint_returns_200_with_valid_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'user',
                    'token',
                ],
            ]);
    }

    /** @test */
    public function login_response_structure_unchanged()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'admin@test.local',
            'password' => 'password',
        ]);

        $response->assertStatus(200);

        // Verify expected structure
        $data = $response->json('data');

        $this->assertArrayHasKey('user', $data);
        $this->assertArrayHasKey('token', $data);
        $this->assertIsArray($data['user']);
        $this->assertIsString($data['token']);
    }

    /** @test */
    public function logout_endpoint_returns_200()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // =========================================================================
    // REGRESSION: Attendance Scan
    // =========================================================================

    /** @test */
    public function attendance_scan_endpoint_accessible()
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-qr-token-123',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);

        // Should return 200 or 422 (validation), not 500
        $this->assertContains($response->status(), [200, 201, 422]);
    }

    /** @test */
    public function attendance_scan_response_structure_consistent()
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/v1/attendance/scan', [
            'qr_token' => 'test-token',
            'latitude' => -6.200000,
            'longitude' => 106.816666,
        ]);

        // Should always have success key
        $response->assertJsonStructure(['success']);

        // If successful, should have data
        if ($response->json('success')) {
            $response->assertJsonStructure([
                'success',
                'data',
            ]);
        }
    }

    // =========================================================================
    // REGRESSION: Attendance Reports
    // =========================================================================

    /** @test */
    public function attendance_report_endpoint_returns_200()
    {
        // Create test attendance data
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => Carbon::now()->toDateString(),
            'status' => 'present',
            'check_in_time' => '07:30:00',
        ]);

        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/reports/attendance');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    /** @test */
    public function attendance_report_structure_unchanged()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/reports/attendance');

        $response->assertStatus(200);

        $data = $response->json('data');

        // Verify core structure exists
        $this->assertIsArray($data);
        $this->assertArrayHasKey('success', $response->json());
    }

    /** @test */
    public function dashboard_report_endpoint_returns_200()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    // =========================================================================
    // REGRESSION: Student Card Generation
    // =========================================================================

    /** @test */
    public function student_card_generation_endpoint_accessible()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/students/cards/generate', [
            'student_ids' => [$this->student->id],
        ]);

        // Should not return 500 server error
        $this->assertNotEquals(500, $response->status());

        // Should return 200, 201, or 422
        $this->assertContains($response->status(), [200, 201, 202, 422]);
    }

    /** @test */
    public function student_card_response_has_consistent_structure()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/students/cards/generate', [
            'student_ids' => [$this->student->id],
        ]);

        // Should always have success indicator
        $response->assertJsonStructure(['success']);
    }

    // =========================================================================
    // REGRESSION: Security Events
    // =========================================================================

    /** @test */
    public function security_events_logging_endpoint_accessible()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/security/events');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    /** @test */
    public function security_events_response_structure_consistent()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/security/events');

        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertIsArray($data);
    }

    /** @test */
    public function security_summary_endpoint_returns_200()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/security/summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    // =========================================================================
    // REGRESSION: Core API Health
    // =========================================================================

    /** @test */
    public function auth_me_endpoint_returns_200()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'role_type',
                ],
            ]);
    }

    /** @test */
    public function notifications_endpoint_accessible()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/notifications');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
            ]);
    }

    // =========================================================================
    // REGRESSION: Error Handling
    // =========================================================================

    /** @test */
    public function unauthenticated_requests_return_401_not_500()
    {
        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertStatus(401);
    }

    /** @test */
    public function invalid_endpoints_return_404_not_500()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/non-existent-endpoint');

        $response->assertStatus(404);
    }

    /** @test */
    public function validation_errors_return_422_not_500()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'invalid-email',
            // Missing password
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors',
            ]);
    }

    // =========================================================================
    // REGRESSION: Response Format Consistency
    // =========================================================================

    /** @test */
    public function all_successful_responses_have_success_key()
    {
        Sanctum::actingAs($this->admin);

        $endpoints = [
            '/api/v1/auth/me',
            '/api/v1/admin/dashboard',
            '/api/v1/admin/security/summary',
            '/api/v1/notifications',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            if ($response->status() === 200) {
                $response->assertJsonStructure(['success']);
                $this->assertTrue($response->json('success'));
            }
        }
    }

    /** @test */
    public function all_successful_responses_return_data_key()
    {
        Sanctum::actingAs($this->admin);

        $endpoints = [
            '/api/v1/auth/me',
            '/api/v1/admin/dashboard',
            '/api/v1/admin/security/summary',
        ];

        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);

            if ($response->status() === 200) {
                $response->assertJsonStructure(['data']);
            }
        }
    }
}
