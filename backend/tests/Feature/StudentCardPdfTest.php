<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\School;
use App\Models\StudentCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StudentCardPdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_school_admin_can_download_single_pdf()
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

        // Create active card
        StudentCard::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'qr_hash' => Hash::make('test-token'),
            'issued_at' => now(),
            'issued_by' => $admin->id,
            'is_active' => true,
        ]);

        // Create Academic Year
        AcademicYear::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $response = $this->post("/api/v1/admin/students/{$student->id}/card-pdf");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_school_admin_can_download_bulk_pdf()
    {
        $school = School::factory()->create();
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'school_admin',
        ]);
        $class = ClassModel::factory()->create([
            'school_id' => $school->id,
            'name' => 'X-RPL-1',
        ]);

        $students = User::factory()->count(3)->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => true,
            'class_id' => $class->id,
        ]);

        foreach ($students as $student) {
            StudentCard::create([
                'student_id' => $student->id,
                'school_id' => $school->id,
                'qr_hash' => Hash::make('test-token-'.$student->id),
                'issued_at' => now(),
                'issued_by' => $admin->id,
                'is_active' => true,
            ]);
        }

        AcademicYear::create([
            'school_id' => $school->id,
            'name' => '2025/2026',
            'start_date' => now(),
            'end_date' => now()->addYear(),
            'is_active' => true,
        ]);

        $this->actingAs($admin);

        $response = $this->post("/api/v1/admin/classes/{$class->id}/card-pdf-bulk");

        $response->assertStatus(200);
        $response->assertHeader('content-type', 'application/zip');
    }

    public function test_teacher_cannot_download_pdf()
    {
        $school = School::factory()->create();
        $teacher = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'teacher',
        ]);
        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        StudentCard::create([
            'student_id' => $student->id,
            'school_id' => $school->id,
            'qr_hash' => Hash::make('test-token'),
            'issued_at' => now(),
            'issued_by' => $teacher->id, // Doesn't matter who issued
            'is_active' => true,
        ]);

        $this->actingAs($teacher);

        $response = $this->post("/api/v1/admin/students/{$student->id}/card-pdf");

        $response->assertStatus(403);
    }
}
