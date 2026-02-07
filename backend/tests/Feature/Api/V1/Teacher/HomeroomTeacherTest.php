<?php

namespace Tests\Feature\Api\V1\Teacher;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\School;
use App\Models\StudentNote;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HomeroomTeacherTest extends TestCase
{
    use RefreshDatabase;

    private User $homeroomTeacher;

    private User $otherTeacher;

    private School $school;

    private AcademicYear $academicYear;

    private ClassModel $class;

    private ClassModel $otherClass;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::factory()->create(['name' => 'SMA Negeri 1']);

        // Create academic year
        $this->academicYear = AcademicYear::create([
            'school_id' => $this->school->id,
            'name' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
        ]);

        // Create homeroom teacher
        $this->homeroomTeacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'homeroom_teacher',
            'name' => 'Pak Budi',
            'email' => 'budi.teacher@school.test',
            'is_active' => true,
        ]);

        // Create other teacher
        $this->otherTeacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
            'name' => 'Bu Ani',
            'email' => 'ani.teacher@school.test',
            'is_active' => true,
        ]);

        // Create class assigned to homeroom teacher
        $this->class = ClassModel::create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'name' => 'X-A',
            'grade_level' => 10,
            'homeroom_teacher_id' => $this->homeroomTeacher->id,
            'max_students' => 30,
            'is_active' => true,
        ]);

        // Create another class NOT assigned to this teacher
        $this->otherClass = ClassModel::create([
            'school_id' => $this->school->id,
            'academic_year_id' => $this->academicYear->id,
            'name' => 'X-B',
            'grade_level' => 10,
            'homeroom_teacher_id' => $this->otherTeacher->id,
            'max_students' => 30,
            'is_active' => true,
        ]);

        // Create student in homeroom teacher's class
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'name' => 'Ahmad Rizki',
            'email' => 'ahmad@student.test',
            'is_active' => true,
        ]);

        ClassStudent::create([
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'status' => 'active',
        ]);
    }

    // =========================================================================
    // TEST: Class Summary Access
    // =========================================================================

    /** @test */
    public function only_assigned_homeroom_teacher_can_access_class_summary()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/class-summary');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'class_info',
                    'student_count',
                    'attendance_summary',
                ],
            ]);
    }

    /** @test */
    public function regular_teacher_cannot_access_homeroom_endpoints()
    {
        $regularTeacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher', // Not homeroom_teacher
        ]);

        Sanctum::actingAs($regularTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/class-summary');

        $response->assertStatus(403);
    }

    /** @test */
    public function student_cannot_access_homeroom_endpoints()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/teacher/homeroom/class-summary');

        $response->assertStatus(403);
    }

    /** @test */
    public function guest_cannot_access_homeroom_endpoints()
    {
        $response = $this->getJson('/api/v1/teacher/homeroom/class-summary');

        $response->assertStatus(401);
    }

    /** @test */
    public function class_summary_shows_only_assigned_class_data()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/class-summary');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'class_info' => [
                        'class_id' => $this->class->id,
                        'class_name' => 'X-A',
                        'grade_level' => '10',
                    ],
                ],
            ]);
    }

    // =========================================================================
    // TEST: Student Notes - Read
    // =========================================================================

    /** @test */
    public function homeroom_teacher_can_view_student_notes()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/student-notes');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'notes',
                ],
            ]);
    }

    /** @test */
    public function student_notes_only_show_own_class_students()
    {
        // Create note for student in teacher's class
        StudentNote::create([
            'student_id' => $this->student->id,
            'teacher_id' => $this->homeroomTeacher->id,
            'class_id' => $this->class->id,
            'note_type' => 'behavior',
            'content' => 'Good progress',
            'created_at' => Carbon::now(),
        ]);

        // Create student in OTHER class
        $otherStudent = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        ClassStudent::create([
            'class_id' => $this->otherClass->id,
            'student_id' => $otherStudent->id,
            'status' => 'active',
        ]);

        // Create note for student in OTHER class
        StudentNote::create([
            'student_id' => $otherStudent->id,
            'teacher_id' => $this->otherTeacher->id,
            'class_id' => $this->otherClass->id,
            'note_type' => 'behavior',
            'content' => 'Other class note',
            'created_at' => Carbon::now(),
        ]);

        Sanctum::actingAs($this->homeroomTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/student-notes');

        $response->assertStatus(200);

        $notes = $response->json('data.notes');

        // Should only see notes for own class
        foreach ($notes as $note) {
            $this->assertEquals($this->class->id, $note['class_id']);
            $this->assertNotEquals($this->otherClass->id, $note['class_id']);
        }
    }

    // =========================================================================
    // TEST: Student Notes - Create
    // =========================================================================

    /** @test */
    public function homeroom_teacher_can_create_student_note()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $noteData = [
            'student_id' => $this->student->id,
            'note_type' => 'achievement',
            'content' => 'Excellent participation in class',
            'date' => Carbon::now()->toDateString(),
        ];

        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', $noteData);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Note created successfully',
            ]);

        // Verify note is stored in database
        $this->assertDatabaseHas('student_notes', [
            'student_id' => $this->student->id,
            'teacher_id' => $this->homeroomTeacher->id,
            'class_id' => $this->class->id,
            'note_type' => 'achievement',
            'content' => 'Excellent participation in class',
        ]);
    }

    /** @test */
    public function notes_stored_correctly_with_all_required_fields()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $noteData = [
            'student_id' => $this->student->id,
            'note_type' => 'behavior',
            'content' => 'Needs improvement in discipline',
            'date' => '2026-02-02',
        ];

        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', $noteData);

        $response->assertStatus(201);

        // Verify all fields are stored correctly
        $note = StudentNote::latest()->first();

        $this->assertEquals($this->student->id, $note->student_id);
        $this->assertEquals($this->homeroomTeacher->id, $note->teacher_id);
        $this->assertEquals($this->class->id, $note->class_id);
        $this->assertEquals('behavior', $note->note_type);
        $this->assertEquals('Needs improvement in discipline', $note->content);
    }

    /** @test */
    public function cannot_create_note_without_required_fields()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        // Missing student_id
        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', [
            'note_type' => 'behavior',
            'content' => 'Test note',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['student_id']);

        // Missing content
        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', [
            'student_id' => $this->student->id,
            'note_type' => 'behavior',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['content']);
    }

    /** @test */
    public function cannot_write_notes_for_students_in_other_classes()
    {
        // Create student in OTHER class
        $otherStudent = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'name' => 'Budi from other class',
        ]);

        ClassStudent::create([
            'class_id' => $this->otherClass->id,
            'student_id' => $otherStudent->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->homeroomTeacher);

        $noteData = [
            'student_id' => $otherStudent->id, // Student from OTHER class
            'note_type' => 'behavior',
            'content' => 'Trying to write note for student not in my class',
        ];

        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', $noteData);

        // Should be forbidden (403) or validation error (422)
        $this->assertContains($response->status(), [403, 422]);

        // Verify note is NOT stored
        $this->assertDatabaseMissing('student_notes', [
            'student_id' => $otherStudent->id,
            'teacher_id' => $this->homeroomTeacher->id,
            'content' => 'Trying to write note for student not in my class',
        ]);
    }

    /** @test */
    public function cannot_write_notes_for_students_from_other_schools()
    {
        // Create another school with student
        $otherSchool = School::factory()->create(['name' => 'SMA Negeri 2']);
        $studentFromOtherSchool = User::factory()->create([
            'school_id' => $otherSchool->id,
            'role_type' => 'student',
        ]);

        Sanctum::actingAs($this->homeroomTeacher);

        $noteData = [
            'student_id' => $studentFromOtherSchool->id,
            'note_type' => 'behavior',
            'content' => 'Trying to write note for student from other school',
        ];

        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', $noteData);

        $this->assertContains($response->status(), [403, 422]);

        // Note should NOT be created
        $this->assertDatabaseMissing('student_notes', [
            'student_id' => $studentFromOtherSchool->id,
            'teacher_id' => $this->homeroomTeacher->id,
        ]);
    }

    /** @test */
    public function note_type_must_be_valid()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $noteData = [
            'student_id' => $this->student->id,
            'note_type' => 'invalid_type',
            'content' => 'Test note',
        ];

        $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', $noteData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['note_type']);
    }

    /** @test */
    public function valid_note_types_are_accepted()
    {
        Sanctum::actingAs($this->homeroomTeacher);

        $validTypes = ['behavior', 'achievement', 'academic', 'attendance', 'general'];

        foreach ($validTypes as $type) {
            $response = $this->postJson('/api/v1/teacher/homeroom/student-notes', [
                'student_id' => $this->student->id,
                'note_type' => $type,
                'content' => "Testing {$type} note type",
            ]);

            $response->assertStatus(201);

            $this->assertDatabaseHas('student_notes', [
                'student_id' => $this->student->id,
                'note_type' => $type,
            ]);
        }
    }

    // =========================================================================
    // TEST: Multi-tenant Isolation
    // =========================================================================

    /** @test */
    public function homeroom_teacher_only_sees_own_school_data()
    {
        // Create another school with its own teacher and student
        $otherSchool = School::factory()->create(['name' => 'SMA Negeri 2']);

        $otherSchoolTeacher = User::factory()->create([
            'school_id' => $otherSchool->id,
            'role_type' => 'homeroom_teacher',
        ]);

        $otherSchoolClass = ClassModel::create([
            'school_id' => $otherSchool->id,
            'name' => 'X-A',
            'grade_level' => '10',
            'homeroom_teacher_id' => $otherSchoolTeacher->id,
            'is_active' => true,
        ]);

        $otherSchoolStudent = User::factory()->create([
            'school_id' => $otherSchool->id,
            'role_type' => 'student',
        ]);

        // Create note in other school
        StudentNote::create([
            'student_id' => $otherSchoolStudent->id,
            'teacher_id' => $otherSchoolTeacher->id,
            'class_id' => $otherSchoolClass->id,
            'note_type' => 'behavior',
            'content' => 'Note from other school',
        ]);

        // Login as our teacher
        Sanctum::actingAs($this->homeroomTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/student-notes');

        $response->assertStatus(200);

        $notes = $response->json('data.notes');

        // Should NOT see notes from other school
        foreach ($notes as $note) {
            $this->assertNotEquals($otherSchool->id, $note['school_id'] ?? null);
            $this->assertNotEquals($otherSchoolStudent->id, $note['student_id']);
        }
    }

    /** @test */
    public function inactive_homeroom_teacher_cannot_access_endpoints()
    {
        $this->homeroomTeacher->update(['is_active' => false]);

        Sanctum::actingAs($this->homeroomTeacher);

        $response = $this->getJson('/api/v1/teacher/homeroom/class-summary');

        $response->assertStatus(403);
    }
}
