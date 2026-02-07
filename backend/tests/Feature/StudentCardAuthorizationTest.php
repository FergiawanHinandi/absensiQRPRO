<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Student Card Authorization Test
 *
 * CRITICAL SECURITY TESTS:
 * - Only School Admin can generate/regenerate/deactivate cards
 * - Teachers are EXPLICITLY DENIED access
 * - Cross-school access is prevented
 * - All unauthorized attempts are logged
 */
class StudentCardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private User $schoolAdmin;

    private User $teacher;

    private User $student;

    private User $otherSchoolAdmin;

    private User $otherStudent;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::factory()->create();

        // Create other school for cross-school testing
        $otherSchool = School::factory()->create();

        // Create users
        $this->schoolAdmin = User::factory()->create([
            'role_type' => 'school_admin',
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $this->teacher = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        $this->student = User::factory()->create([
            'role_type' => 'student',
            'school_id' => $this->school->id,
            'is_active' => true,
        ]);

        // Other school users
        $this->otherSchoolAdmin = User::factory()->create([
            'role_type' => 'school_admin',
            'school_id' => $otherSchool->id,
            'is_active' => true,
        ]);

        $this->otherStudent = User::factory()->create([
            'role_type' => 'student',
            'school_id' => $otherSchool->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function school_admin_can_generate_student_card()
    {
        Sanctum::actingAs($this->schoolAdmin);

        $response = $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'card_id',
                    'card_number',
                    'qr_code',
                    'expires_at',
                    'student',
                    'generated_at',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('student_cards', [
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
            'is_active' => true,
            'generated_by' => $this->schoolAdmin->id,
        ]);
    }

    /** @test */
    public function teacher_cannot_generate_student_card()
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. Only School Admin can generate student cards.',
                'error_code' => 'INSUFFICIENT_PRIVILEGES',
            ]);

        $this->assertDatabaseMissing('student_cards', [
            'student_id' => $this->student->id,
        ]);
    }

    /** @test */
    public function school_admin_cannot_generate_card_for_other_school_student()
    {
        Sanctum::actingAs($this->schoolAdmin);

        $response = $this->postJson("/api/v1/admin/students/{$this->otherStudent->id}/generate-card");

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Student not found or not in your school.',
                'error_code' => 'STUDENT_NOT_FOUND',
            ]);

        $this->assertDatabaseMissing('student_cards', [
            'student_id' => $this->otherStudent->id,
        ]);
    }

    /** @test */
    public function school_admin_can_regenerate_existing_card()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // First generate a card
        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        // Then regenerate it
        $response = $this->postJson("/api/v1/admin/students/{$this->student->id}/regenerate-card");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'card_id',
                    'card_number',
                    'qr_code',
                    'expires_at',
                    'student',
                    'generated_at',
                    'previous_card_id',
                ],
                'message',
            ]);

        // Should have 2 cards total (1 active, 1 deactivated)
        $this->assertDatabaseCount('student_cards', 2);

        // Only 1 should be active
        $this->assertDatabaseCount('student_cards', 1, [
            'student_id' => $this->student->id,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function teacher_cannot_regenerate_student_card()
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/v1/admin/students/{$this->student->id}/regenerate-card");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. Only School Admin can regenerate student cards.',
                'error_code' => 'INSUFFICIENT_PRIVILEGES',
            ]);
    }

    /** @test */
    public function school_admin_can_deactivate_student_card()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // First generate a card
        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        // Then deactivate it
        $response = $this->postJson("/api/v1/admin/students/{$this->student->id}/deactivate-card");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'card_id',
                    'card_number',
                    'deactivated_at',
                    'student',
                ],
                'message',
            ]);

        $this->assertDatabaseHas('student_cards', [
            'student_id' => $this->student->id,
            'is_active' => false,
            'deactivated_by' => $this->schoolAdmin->id,
            'deactivation_reason' => 'manual_deactivation',
        ]);
    }

    /** @test */
    public function teacher_cannot_deactivate_student_card()
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/v1/admin/students/{$this->student->id}/deactivate-card");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized. Only School Admin can deactivate student cards.',
                'error_code' => 'INSUFFICIENT_PRIVILEGES',
            ]);
    }

    /** @test */
    public function school_admin_can_view_card_status()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // Generate a card first
        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        $response = $this->getJson("/api/v1/admin/students/{$this->student->id}/card-status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'has_active_card',
                    'active_card',
                    'total_cards_generated',
                    'last_generated',
                    'card_history',
                ],
                'message',
            ]);
    }

    /** @test */
    public function teacher_cannot_view_card_status()
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson("/api/v1/admin/students/{$this->student->id}/card-status");

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized to view student card status.',
                'error_code' => 'INSUFFICIENT_PRIVILEGES',
            ]);
    }

    /** @test */
    public function unauthorized_attempts_are_logged()
    {
        Sanctum::actingAs($this->teacher);

        // Attempt unauthorized action
        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        // Check that security violation was logged
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->teacher->id,
            'action' => 'security_violation_student_card',
            'school_id' => $this->school->id,
        ]);
    }

    /** @test */
    public function successful_card_operations_are_audited()
    {
        Sanctum::actingAs($this->schoolAdmin);

        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        // Check that action was logged
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->schoolAdmin->id,
            'action' => 'student_card_generated',
            'school_id' => $this->school->id,
        ]);
    }

    /** @test */
    public function only_one_active_card_per_student()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // Generate first card
        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        // Generate second card (should deactivate first)
        $this->postJson("/api/v1/admin/students/{$this->student->id}/generate-card");

        // Should have exactly 1 active card
        $activeCards = StudentCard::where('student_id', $this->student->id)
            ->where('is_active', true)
            ->count();

        $this->assertEquals(1, $activeCards);

        // Should have 2 total cards
        $totalCards = StudentCard::where('student_id', $this->student->id)->count();
        $this->assertEquals(2, $totalCards);
    }

    /** @test */
    public function middleware_blocks_non_school_admin_routes()
    {
        Sanctum::actingAs($this->teacher);

        // Test all protected routes
        $protectedRoutes = [
            ['POST', "/api/v1/admin/students/{$this->student->id}/generate-card"],
            ['POST', "/api/v1/admin/students/{$this->student->id}/regenerate-card"],
            ['POST', "/api/v1/admin/students/{$this->student->id}/deactivate-card"],
        ];

        foreach ($protectedRoutes as [$method, $route]) {
            $response = $this->json($method, $route);

            $this->assertEquals(403, $response->getStatusCode(),
                "Route {$method} {$route} should be blocked for teachers");
        }
    }
}
