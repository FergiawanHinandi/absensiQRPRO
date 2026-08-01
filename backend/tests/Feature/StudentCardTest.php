<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StudentCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_generate_student_card()
    {
        $school = School::factory()->create();
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'school_admin',
        ]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $response = $this->postJson("/api/v1/admin/student-cards/{$student->id}/generate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'card',
                    'plain_token',
                    'qr_string',
                ],
            ]);

        $data = $response->json('data');
        $card = $data['card'];

        // Assert DB
        $dbCard = StudentCard::find($card['id']);
        $this->assertNotNull($dbCard);
        $this->assertEquals($student->id, $dbCard->student_id);
        $this->assertTrue($dbCard->is_active);
        $this->assertEquals($admin->id, $dbCard->issued_by);

        // Assert qr_string format: {card_id}|{plain_token}
        $this->assertStringContainsString('|', $data['qr_string']);
        [$cardIdPart, $tokenPart] = explode('|', $data['qr_string']);
        $this->assertEquals($card['id'], (int) $cardIdPart);
        $this->assertEquals($data['plain_token'], $tokenPart);
    }

    public function test_teacher_cannot_generate_student_card()
    {
        $school = School::factory()->create();
        $teacher = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'teacher',
        ]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
        ]);

        $this->actingAs($teacher);

        $response = $this->postJson("/api/v1/admin/student-cards/{$student->id}/generate");

        $response->assertStatus(403);
    }

    public function test_second_generation_revokes_previous_card()
    {
        $school = School::factory()->create();
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'school_admin',
        ]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        // Generate first card
        $response1 = $this->postJson("/api/v1/admin/student-cards/{$student->id}/generate");
        $response1->assertStatus(200);
        $firstCardId = $response1->json('data.card.id');
        $firstQrString = $response1->json('data.qr_string');

        // Generate second card (auto-revokes first)
        $response2 = $this->postJson("/api/v1/admin/student-cards/{$student->id}/generate");
        $response2->assertStatus(200);
        $secondCardId = $response2->json('data.card.id');

        // First card should now be inactive
        $firstCard = StudentCard::find($firstCardId);
        $this->assertFalse($firstCard->is_active);
        $this->assertNotNull($firstCard->revoked_at);

        // Second card should be active
        $secondCard = StudentCard::find($secondCardId);
        $this->assertTrue($secondCard->is_active);

        // Only 1 active card per student
        $activeCount = StudentCard::where('student_id', $student->id)
            ->where('is_active', true)
            ->count();
        $this->assertEquals(1, $activeCount);
    }
}
