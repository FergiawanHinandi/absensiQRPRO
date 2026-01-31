<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\StudentQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed roles if necessary or mock them. 
        // Assuming factories handle basic setup.
    }

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

        $response = $this->postJson("/api/v1/student-cards/{$student->id}/generate");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'card_id',
                    'qr_token',
                    'student_id',
                    'school_id',
                    'issued_at',
                ]
            ]);

        $data = $response->json('data');
        $token = $data['qr_token'];

        // Assert DB
        $card = StudentCard::find($data['card_id']);
        $this->assertNotNull($card);
        $this->assertEquals($student->id, $card->student_id);
        $this->assertTrue($card->is_active);
        $this->assertEquals($admin->id, $card->issued_by);
        
        // Assert Hash
        [$cardId, $rawToken] = explode('|', $token);
        $this->assertTrue(Hash::check($rawToken, $card->qr_hash));
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

        $response = $this->postJson("/api/v1/student-cards/{$student->id}/generate");

        $response->assertStatus(403);
    }

    public function test_verify_generated_card_token()
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

        // Generate Card
        $response = $this->postJson("/api/v1/student-cards/{$student->id}/generate");
        $token = $response->json('data.qr_token');

        // Verify using Service
        $service = app(StudentQrService::class);
        $payload = $service->verify($token);

        $this->assertEquals($student->id, $payload['sid']);
        $this->assertEquals($school->id, $payload['sch']);
        $this->assertEquals('student_card', $payload['typ']);
    }

    public function test_deactivated_card_fails_verification()
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

        // Generate Card
        $response = $this->postJson("/api/v1/student-cards/{$student->id}/generate");
        $token = $response->json('data.qr_token');
        $cardId = $response->json('data.card_id');

        // Deactivate
        $this->deleteJson("/api/v1/student-cards/{$student->id}")
            ->assertStatus(200);

        // Verify fail
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Kartu ini sudah dinonaktifkan'); // Or whatever message I put
        
        $service = app(StudentQrService::class);
        $service->verify($token);
    }
}
