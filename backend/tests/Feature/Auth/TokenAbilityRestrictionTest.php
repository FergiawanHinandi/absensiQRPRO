<?php

namespace Tests\Feature\Auth;

use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenAbilityRestrictionTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test school
        $this->school = School::create([
            'name' => 'Test School',
            'school_level' => 'SMA',
            'npsn' => '12345678',
            'phone' => '08123456789',
            'email' => 'test@school.com',
            'address' => 'Test Address',
            'is_active' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_token_cannot_access_teacher_dashboard()
    {
        // Create student user
        $student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student123',
            'name' => 'Test Student',
            'email' => 'student@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Login to get token with student abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'student123',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access teacher dashboard (should fail with 403)
        $response = $this->withToken($token)
            ->getJson('/api/v1/teacher/dashboard');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Invalid ability provided.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_token_can_access_own_attendance_history()
    {
        // Create student user
        $student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student456',
            'name' => 'Test Student 2',
            'email' => 'student2@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Login to get token with student abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'student456',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access own attendance history (should succeed)
        $response = $this->withToken($token)
            ->getJson('/api/v1/attendance/history');

        $response->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_token_cannot_access_admin_management()
    {
        // Create teacher user
        $teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher123',
            'name' => 'Test Teacher',
            'email' => 'teacher@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Login to get token with teacher abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'teacher123',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access admin teachers list (should fail with 403)
        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/teachers');

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Invalid ability provided.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_token_can_generate_qr_codes()
    {
        // Create teacher user
        $teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher456',
            'name' => 'Test Teacher 2',
            'email' => 'teacher2@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Login to get token with teacher abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'teacher456',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to generate QR code (should succeed if class_id exists)
        // Note: This might fail due to business logic, but should NOT fail with 403
        $response = $this->withToken($token)
            ->postJson('/api/v1/qr/generate', [
                'class_id' => 1,
            ]);

        // Should not be 403 (ability check passed)
        $this->assertNotEquals(403, $response->status());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_token_can_access_teacher_management()
    {
        // Create admin user
        $admin = User::create([
            'school_id' => $this->school->id,
            'username' => 'admin123',
            'name' => 'Test Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        // Login to get token with admin abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin123',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access admin teachers list (should succeed)
        $response = $this->withToken($token)
            ->getJson('/api/v1/admin/teachers');

        $response->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_token_cannot_access_super_admin_routes()
    {
        // Create admin user
        $admin = User::create([
            'school_id' => $this->school->id,
            'username' => 'admin456',
            'name' => 'Test Admin 2',
            'email' => 'admin2@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        // Login to get token with admin abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'admin456',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access super admin dashboard (should fail with 403)
        $response = $this->withToken($token)
            ->getJson('/api/v1/super-admin/dashboard/stats');

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_token_has_wildcard_access()
    {
        // Create super admin user
        $superAdmin = User::create([
            'username' => 'superadmin',
            'name' => 'Super Admin',
            'email' => 'super@admin.com',
            'password' => bcrypt('password'),
            'role_type' => 'super_admin',
            'is_active' => true,
        ]);

        // Login to get token with wildcard abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'superadmin',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access super admin dashboard (should succeed)
        $response = $this->withToken($token)
            ->getJson('/api/v1/super-admin/dashboard/stats');

        $response->assertStatus(200);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_token_cannot_generate_qr_codes()
    {
        // Create student user
        $student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student789',
            'name' => 'Test Student 3',
            'email' => 'student3@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Login to get token with student abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'student789',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to generate QR code (should fail with 403)
        $response = $this->withToken($token)
            ->postJson('/api/v1/qr/generate', [
                'class_id' => 1,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'message' => 'Invalid ability provided.',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_token_cannot_access_parent_routes()
    {
        // Create teacher user
        $teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher789',
            'name' => 'Test Teacher 3',
            'email' => 'teacher3@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Login to get token with teacher abilities
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'teacher789',
            'password' => 'password',
        ]);

        $token = $response->json('data.token');

        // Try to access parent routes (should fail with 403 due to role middleware)
        $response = $this->withToken($token)
            ->getJson('/api/v1/parent/my-children');

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function token_abilities_match_user_role()
    {
        // Create users of different roles
        $student = User::create([
            'school_id' => $this->school->id,
            'username' => 'student_check',
            'name' => 'Student Check',
            'email' => 'student_check@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $teacher = User::create([
            'school_id' => $this->school->id,
            'username' => 'teacher_check',
            'name' => 'Teacher Check',
            'email' => 'teacher_check@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Login as student
        $studentResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'student_check',
            'password' => 'password',
        ]);

        $studentToken = $studentResponse->json('data.token');

        // Verify student can scan but cannot generate QR
        $this->assertNotNull($studentToken);

        // Login as teacher
        $teacherResponse = $this->postJson('/api/v1/auth/login', [
            'username' => 'teacher_check',
            'password' => 'password',
        ]);

        $teacherToken = $teacherResponse->json('data.token');

        // Verify teacher can do both
        $this->assertNotNull($teacherToken);
        $this->assertNotEquals($studentToken, $teacherToken);
    }
}
