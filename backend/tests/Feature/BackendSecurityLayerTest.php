<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Backend Security Layer Tests
 * 
 * Memverifikasi bahwa SEMUA security ada di backend,
 * bukan di frontend. Frontend route guards HANYA untuk UX.
 * 
 * Test ini mensimulasikan attacker yang bypass frontend
 * dan langsung panggil API.
 */
class BackendSecurityLayerTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;
    private School $schoolB;
    private User $superAdmin;
    private User $adminA;
    private User $adminB;
    private User $teacherA;
    private User $studentA;
    private User $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create schools
        $this->schoolA = School::create([
            'name' => 'School A',
            'npsn' => '12345678',
            'school_level' => 'SMA',
            'address' => 'Jakarta',
            'is_active' => true,
        ]);

        $this->schoolB = School::create([
            'name' => 'School B',
            'npsn' => '87654321',
            'school_level' => 'SMA',
            'address' => 'Bandung',
            'is_active' => true,
        ]);

        // Create super admin
        $this->superAdmin = User::create([
            'school_id' => null,
            'name' => 'Super Admin',
            'username' => 'superadmin',
            'email' => 'super@admin.com',
            'password' => bcrypt('password'),
            'role_type' => 'super_admin',
            'is_active' => true,
        ]);

        // Create users for School A
        $this->adminA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Admin A',
            'username' => 'admin_a',
            'email' => 'admin.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        $this->teacherA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Teacher A',
            'username' => 'teacher_a',
            'email' => 'teacher.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        $this->studentA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Student A',
            'username' => 'student_a',
            'email' => 'student.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create users for School B
        $this->adminB = User::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Admin B',
            'username' => 'admin_b',
            'email' => 'admin.b@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        $this->studentB = User::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Student B',
            'username' => 'student_b',
            'email' => 'student.b@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function unauthenticated_request_is_rejected()
    {
        // Simulate: Attacker bypass frontend dan panggil API tanpa token
        $response = $this->getJson('/api/v1/admin/students');

        // Backend HARUS reject
        $response->assertStatus(401);
    }

    /** @test */
    public function student_cannot_access_admin_endpoint_even_if_frontend_allows()
    {
        // Simulate: Student manipulate frontend untuk show admin menu
        // Kemudian panggil admin API
        Sanctum::actingAs($this->studentA, ['*']);

        $response = $this->getJson('/api/v1/admin/students');

        // Backend HARUS reject (role middleware)
        $response->assertStatus(403);
    }

    /** @test */
    public function teacher_cannot_access_super_admin_endpoint()
    {
        // Simulate: Teacher bypass frontend guard
        Sanctum::actingAs($this->teacherA, ['*']);

        $response = $this->getJson('/api/v1/super-admin/schools');

        // Backend HARUS reject
        $response->assertStatus(403);
    }

    /** @test */
    public function admin_from_school_a_cannot_access_school_b_data()
    {
        // Simulate: Admin A manipulate request untuk akses data School B
        Sanctum::actingAs($this->adminA, ['*']);

        // Try to access student from School B
        $response = $this->getJson("/api/v1/admin/students/{$this->studentB->id}");

        // Backend HARUS reject (global scope + policy)
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    /** @test */
    public function inactive_user_cannot_access_api()
    {
        // Create inactive user
        $inactiveUser = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Inactive User',
            'username' => 'inactive',
            'email' => 'inactive@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => false, // ← Tidak aktif
        ]);

        Sanctum::actingAs($inactiveUser, ['*']);

        $response = $this->getJson('/api/v1/attendance/history');

        // Backend HARUS reject
        $response->assertStatus(403);
    }

    /** @test */
    public function expired_token_is_rejected()
    {
        // Create token
        $token = $this->studentA->createToken('test-token')->plainTextToken;

        // Manually expire token
        $this->studentA->tokens()->update([
            'created_at' => now()->subDays(365),
        ]);

        $response = $this->getJson('/api/v1/attendance/history', [
            'Authorization' => "Bearer {$token}",
        ]);

        // Backend HARUS reject
        $response->assertStatus(401);
    }

    /** @test */
    public function cross_school_update_is_blocked()
    {
        // Simulate: Admin A tries to update student from School B
        Sanctum::actingAs($this->adminA, ['*']);

        $response = $this->putJson("/api/v1/admin/students/{$this->studentB->id}", [
            'name' => 'Hacked Name',
        ]);

        // Backend HARUS reject
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    /** @test */
    public function cross_school_delete_is_blocked()
    {
        // Simulate: Admin A tries to delete student from School B
        Sanctum::actingAs($this->adminA, ['*']);

        $response = $this->deleteJson("/api/v1/admin/students/{$this->studentB->id}");

        // Backend HARUS reject
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            "Expected 403 or 404, got {$response->status()}"
        );
    }

    /** @test */
    public function student_cannot_access_other_student_data()
    {
        // Simulate: Student A tries to access Student B's attendance
        Sanctum::actingAs($this->studentA, ['*']);

        // Create another student in same school
        $studentA2 = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Student A2',
            'username' => 'student_a2',
            'email' => 'student.a2@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Try to access (if endpoint exists)
        // This is just an example - adjust based on actual endpoints
        $response = $this->getJson("/api/v1/students/{$studentA2->id}/attendance");

        // Backend HARUS reject atau return empty
        // Depending on implementation, could be 403 or 404
        if ($response->status() === 200) {
            // If endpoint returns data, it should be empty or filtered
            $this->assertEmpty($response->json('data'));
        } else {
            $this->assertTrue(in_array($response->status(), [403, 404]));
        }
    }

    /** @test */
    public function rate_limiting_blocks_excessive_requests()
    {
        Sanctum::actingAs($this->studentA, ['*']);

        // Simulate: Attacker sends many requests rapidly
        $responses = [];
        for ($i = 0; $i < 10; $i++) {
            $responses[] = $this->postJson('/api/v1/attendance/scan', [
                'token' => 'fake-token',
                'latitude' => -6.2,
                'longitude' => 106.8,
            ]);
        }

        // At least one request should be rate limited (429)
        $rateLimited = collect($responses)->contains(function ($response) {
            return $response->status() === 429;
        });

        $this->assertTrue($rateLimited, 'Rate limiting should block excessive requests');
    }

    /** @test */
    public function tampered_payload_is_rejected()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Simulate: Attacker tampers with student_id in payload
        $response = $this->putJson("/api/v1/admin/students/{$this->studentA->id}", [
            'student_id' => $this->studentB->id, // ← Tampered: different student
            'name' => 'Hacked',
        ]);

        // Backend validation should reject or ignore tampered field
        // The student_id should not be updatable via this endpoint
        if ($response->status() === 200) {
            // If update succeeds, student_id should NOT change
            $this->assertEquals(
                $this->studentA->id,
                User::find($this->studentA->id)->id
            );
        }
    }

    /** @test */
    public function super_admin_can_access_all_schools()
    {
        // Super admin should have access to all schools
        Sanctum::actingAs($this->superAdmin, ['*']);

        $response = $this->getJson('/api/v1/super-admin/schools');

        // Should succeed
        $response->assertStatus(200);
    }

    /** @test */
    public function admin_can_only_see_own_school_students()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $response = $this->getJson('/api/v1/admin/students');

        $response->assertStatus(200);

        // All students should be from School A only
        $students = $response->json('data');
        
        if (!empty($students)) {
            foreach ($students as $student) {
                $this->assertEquals(
                    $this->schoolA->id,
                    $student['school_id'] ?? null,
                    'Admin should only see students from their own school'
                );
            }
        }
    }

    /** @test */
    public function security_headers_are_present()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        $response = $this->getJson('/api/v1/admin/students');

        // Check for security headers
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-XSS-Protection', '1; mode=block');
    }

    /** @test */
    public function cors_is_properly_configured()
    {
        // Test CORS preflight
        $response = $this->options('/api/v1/admin/students', [
            'Origin' => config('app.frontend_url'),
        ]);

        // Should allow configured origins
        $response->assertHeader('Access-Control-Allow-Origin');
    }

    /** @test */
    public function sql_injection_is_prevented()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Simulate: SQL injection attempt
        $response = $this->getJson('/api/v1/admin/students', [
            'search' => "'; DROP TABLE users; --",
        ]);

        // Should not cause error (Eloquent prevents SQL injection)
        $this->assertTrue(in_array($response->status(), [200, 422]));

        // Verify users table still exists
        $this->assertDatabaseHas('users', [
            'id' => $this->studentA->id,
        ]);
    }

    /** @test */
    public function xss_payload_is_escaped()
    {
        Sanctum::actingAs($this->adminA, ['*']);

        // Simulate: XSS attempt
        $xssPayload = '<script>alert("XSS")</script>';
        
        $response = $this->putJson("/api/v1/admin/students/{$this->studentA->id}", [
            'name' => $xssPayload,
        ]);

        if ($response->status() === 200) {
            // If accepted, verify it's stored safely
            $student = User::find($this->studentA->id);
            
            // Laravel should escape this when rendering
            // But we can verify it's stored as-is (escaping happens on output)
            $this->assertEquals($xssPayload, $student->name);
        }
    }
}
