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

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_admin_can_generate_student_card()
    {
        Sanctum::actingAs($this->schoolAdmin);

        $response = $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'card',
                    'plain_token',
                    'qr_string',
                ],
            ]);

        $this->assertDatabaseHas('student_cards', [
            'student_id' => $this->student->id,
            'school_id' => $this->school->id,
            'is_active' => true,
            'issued_by' => $this->schoolAdmin->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_cannot_generate_student_card()
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        $response->assertStatus(403);

        $this->assertDatabaseMissing('student_cards', [
            'student_id' => $this->student->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_admin_cannot_generate_card_for_other_school_student()
    {
        Sanctum::actingAs($this->schoolAdmin);

        $response = $this->postJson("/api/v1/admin/student-cards/{$this->otherStudent->id}/generate");

        $response->assertStatus(404);

        $this->assertDatabaseMissing('student_cards', [
            'student_id' => $this->otherStudent->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_admin_can_regenerate_existing_card()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // First generate a card
        $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        // Then regenerate it (generate again = auto-revoke old, create new)
        $response = $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'card',
                    'plain_token',
                    'qr_string',
                ],
            ]);

        // Should have 2 cards total (1 active, 1 deactivated)
        $this->assertDatabaseCount('student_cards', 2);

        // Only 1 should be active
        $this->assertEquals(1, StudentCard::where('student_id', $this->student->id)
            ->where('is_active', true)
            ->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_admin_can_deactivate_student_card()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // First generate a card
        $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        // Then deactivate it
        $response = $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/deactivate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'message',
            ]);

        $this->assertDatabaseHas('student_cards', [
            'student_id' => $this->student->id,
            'is_active' => false,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_cannot_deactivate_student_card()
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/deactivate");

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_admin_can_view_card_status()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // Generate a card first
        $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        $response = $this->getJson("/api/v1/admin/student-cards/{$this->student->id}/status");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'has_active_card',
                ],
                'message',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function school_admin_cannot_view_card_status_for_other_school_student()
    {
        Sanctum::actingAs($this->schoolAdmin);

        $response = $this->getJson("/api/v1/admin/student-cards/{$this->otherStudent->id}/status");

        $response->assertStatus(404);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function only_one_active_card_per_student()
    {
        Sanctum::actingAs($this->schoolAdmin);

        // Generate first card
        $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        // Generate second card (should deactivate first)
        $this->postJson("/api/v1/admin/student-cards/{$this->student->id}/generate");

        // Should have exactly 1 active card
        $activeCards = StudentCard::where('student_id', $this->student->id)
            ->where('is_active', true)
            ->count();

        $this->assertEquals(1, $activeCards);

        // Should have 2 total cards
        $totalCards = StudentCard::where('student_id', $this->student->id)->count();
        $this->assertEquals(2, $totalCards);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function middleware_blocks_non_school_admin_routes()
    {
        Sanctum::actingAs($this->teacher);

        // Test all protected routes (semua di bawah role:school_admin middleware)
        $protectedRoutes = [
            ['POST', "/api/v1/admin/student-cards/{$this->student->id}/generate"],
            ['POST', "/api/v1/admin/student-cards/{$this->student->id}/deactivate"],
            ['GET', "/api/v1/admin/student-cards/{$this->student->id}/status"],
        ];

        foreach ($protectedRoutes as [$method, $route]) {
            $response = $this->json($method, $route);

            $this->assertEquals(403, $response->getStatusCode(),
                "Route {$method} {$route} should be blocked for teachers");
        }
    }
}
