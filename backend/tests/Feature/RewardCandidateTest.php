<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel; // Assuming ClassModel or Classes
use App\Models\ClassStudent;
use App\Models\School;
use App\Models\TeacherRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RewardCandidateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Additional setup if needed
    }

    public function test_get_reward_candidates_success()
    {
        // 1. Setup Data
        $school = School::factory()->create();
        $academicYear = AcademicYear::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
        ]);

        // Create Class (using DB directly if Factory missing or assuming 'classes' table)
        // Check if ClassModel exists or Classes. TeacherRole used 'Classes' in belongsTo hint but usually it's ClassModel in Laravel to avoid keyword conflict?
        // Let's use DB insert for safety or ClassModel if I can find it. 
        // I'll check ClassModelFactory usage in other tests if I could, but let's assume ClassModel exists based on file list.
        $class = \App\Models\ClassModel::factory()->create([
            'school_id' => $school->id,
            'academic_year_id' => $academicYear->id,
        ]);

        // Create Teacher (Homeroom)
        $teacher = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'teacher',
        ]);
        
        TeacherRole::create([
            'teacher_id' => $teacher->id,
            'academic_year_id' => $academicYear->id,
            'is_homeroom_teacher' => true,
            'homeroom_class_id' => $class->id,
        ]);

        // Create Students
        // A. Close to Silver (198 points)
        $studentSilver = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'name' => 'Silver Candidate',
            'total_points' => 198,
            'current_streak' => 0,
        ]);
        
        // B. Close to Gold (495 points)
        $studentGold = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'name' => 'Gold Candidate',
            'total_points' => 495,
            'current_streak' => 0,
        ]);

        // C. Close to Streak (28 days)
        $studentStreak = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'name' => 'Streak Candidate',
            'total_points' => 0,
            'current_streak' => 28,
        ]);

        // D. Normal Student (No rewards close)
        $studentNormal = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'name' => 'Normal Student',
            'total_points' => 100,
            'current_streak' => 10,
        ]);

        // Assign Students to Class
        foreach ([$studentSilver, $studentGold, $studentStreak, $studentNormal] as $student) {
            ClassStudent::create([
                'student_id' => $student->id,
                'class_id' => $class->id,
                'status' => 'active',
                'enrollment_date' => now(),
            ]);
        }

        // 2. Act
        Sanctum::actingAs($teacher);
        $response = $this->getJson('/api/v1/teacher/homeroom/reward-candidates');

        // 3. Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'close_to_level_up' => [
                        '*' => ['id', 'name', 'current_points', 'next_level', 'points_needed']
                    ],
                    'close_to_streak_reward' => [
                        '*' => ['id', 'name', 'current_streak', 'days_needed']
                    ]
                ]
            ]);

        // Verify Data Content
        $data = $response->json('data');
        
        // Verify Level Up Candidates
        $this->assertCount(2, $data['close_to_level_up']);
        
        $silverCandidate = collect($data['close_to_level_up'])->firstWhere('id', $studentSilver->id);
        $this->assertNotNull($silverCandidate);
        $this->assertEquals('Silver', $silverCandidate['next_level']);
        $this->assertEquals(3, $silverCandidate['points_needed']); // 201 - 198 = 3

        $goldCandidate = collect($data['close_to_level_up'])->firstWhere('id', $studentGold->id);
        $this->assertNotNull($goldCandidate);
        $this->assertEquals('Gold', $goldCandidate['next_level']);
        $this->assertEquals(6, $goldCandidate['points_needed']); // 501 - 495 = 6

        // Verify Streak Candidates
        $this->assertCount(1, $data['close_to_streak_reward']);
        $streakCandidate = collect($data['close_to_streak_reward'])->firstWhere('id', $studentStreak->id);
        $this->assertNotNull($streakCandidate);
        $this->assertEquals(28, $streakCandidate['current_streak']);
        $this->assertEquals(2, $streakCandidate['days_needed']); // 30 - 28 = 2
    }

    public function test_get_reward_candidates_not_homeroom()
    {
        // Setup
        $school = School::factory()->create();
        $academicYear = AcademicYear::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
        ]);
        
        $teacher = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'teacher',
        ]);
        
        // No TeacherRole or is_homeroom_teacher = false

        Sanctum::actingAs($teacher);
        $response = $this->getJson('/api/v1/teacher/homeroom/reward-candidates');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => []
            ]);
    }
}
