<?php

namespace Tests\Feature\Authorization;

use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected School $schoolA;

    protected School $schoolB;

    protected User $adminA;

    protected User $adminB;

    protected User $teacherA;

    protected User $teacherB;

    protected User $studentA;

    protected User $studentB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two separate schools
        $this->schoolA = School::create([
            'name' => 'School A',
            'school_level' => 'SMA',
            'npsn' => '11111111',
            'phone' => '08111111111',
            'email' => 'schoola@test.com',
            'address' => 'Address A',
            'is_active' => true,
        ]);

        $this->schoolB = School::create([
            'name' => 'School B',
            'school_level' => 'SMA',
            'npsn' => '22222222',
            'phone' => '08222222222',
            'email' => 'schoolb@test.com',
            'address' => 'Address B',
            'is_active' => true,
        ]);

        // Create admin users for each school
        $this->adminA = User::create([
            'school_id' => $this->schoolA->id,
            'username' => 'admin_a',
            'name' => 'Admin A',
            'email' => 'admin.a@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        $this->adminB = User::create([
            'school_id' => $this->schoolB->id,
            'username' => 'admin_b',
            'name' => 'Admin B',
            'email' => 'admin.b@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        // Create teacher users for each school
        $this->teacherA = User::create([
            'school_id' => $this->schoolA->id,
            'username' => 'teacher_a',
            'name' => 'Teacher A',
            'email' => 'teacher.a@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        $this->teacherB = User::create([
            'school_id' => $this->schoolB->id,
            'username' => 'teacher_b',
            'name' => 'Teacher B',
            'email' => 'teacher.b@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        // Create student users for each school
        $this->studentA = User::create([
            'school_id' => $this->schoolA->id,
            'username' => 'student_a',
            'name' => 'Student A',
            'email' => 'student.a@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->studentB = User::create([
            'school_id' => $this->schoolB->id,
            'username' => 'student_b',
            'name' => 'Student B',
            'email' => 'student.b@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Create academic years for both schools (required for ClassModel)
        AcademicYear::create([
            'school_id' => $this->schoolA->id,
            'name' => '2025/2026',
            'year' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'semester' => '1',
            'is_active' => true,
        ]);

        AcademicYear::create([
            'school_id' => $this->schoolB->id,
            'name' => '2025/2026',
            'year' => '2025/2026',
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'semester' => '1',
            'is_active' => true,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_from_school_a_cannot_access_school_b_student()
    {
        // Teacher A tries to access Student B (from different school)
        $canView = $this->teacherA->can('view', $this->studentB);

        $this->assertFalse($canView, 'Teacher A should not be able to view Student B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_from_school_a_cannot_access_school_b_student()
    {
        // Admin A tries to access Student B (from different school)
        $canView = $this->adminA->can('view', $this->studentB);

        $this->assertFalse($canView, 'Admin A should not be able to view Student B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_from_school_a_cannot_update_school_b_student()
    {
        // Admin A tries to update Student B (from different school)
        $response = $this->actingAs($this->adminA, 'sanctum')
            ->putJson("/api/v1/admin/students/{$this->studentB->id}", [
                'name' => 'Updated Name',
                'email' => 'updated@test.com',
            ]);

        $response->assertStatus(403);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_from_school_a_cannot_access_school_b_teacher()
    {
        // Teacher A tries to access Teacher B (from different school)
        $canView = $this->teacherA->can('view', $this->teacherB);

        $this->assertFalse($canView, 'Teacher A should not be able to view Teacher B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_from_school_a_cannot_update_school_b_teacher()
    {
        // Admin A tries to update Teacher B (from different school)
        $canUpdate = $this->adminA->can('update', $this->teacherB);

        $this->assertFalse($canUpdate, 'Admin A should not be able to update Teacher B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_access_own_school_students()
    {
        // Admin A can access Student A (same school)
        $canView = $this->adminA->can('view', $this->studentA);

        $this->assertTrue($canView, 'Admin A should be able to view Student A from same school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_can_update_own_school_students()
    {
        // Admin A can update Student A (same school)
        $canUpdate = $this->adminA->can('update', $this->studentA);

        $this->assertTrue($canUpdate, 'Admin A should be able to update Student A from same school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_from_school_a_cannot_access_school_b_class()
    {
        // Get academic year for School B
        $academicYearB = AcademicYear::where('school_id', $this->schoolB->id)->first();

        // Create class for School B
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Class 10A',
            'grade_level' => 10,
            'academic_year_id' => $academicYearB->id,
            'max_students' => 30,
            'is_active' => true,
        ]);

        // Teacher A tries to view Class B
        $this->actingAs($this->teacherA, 'sanctum');

        $canView = $this->teacherA->can('view', $classB);

        $this->assertFalse($canView, 'Teacher A should not be able to view Class B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function admin_from_school_a_cannot_update_school_b_class()
    {
        // Get academic year for School B
        $academicYearB = AcademicYear::where('school_id', $this->schoolB->id)->first();

        // Create class for School B
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Class 10A',
            'grade_level' => 10,
            'academic_year_id' => $academicYearB->id,
            'max_students' => 30,
            'is_active' => true,
        ]);

        // Admin A tries to update Class B
        $canUpdate = $this->adminA->can('update', $classB);

        $this->assertFalse($canUpdate, 'Admin A should not be able to update Class B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function teacher_from_school_a_cannot_access_school_b_schedule()
    {
        // Get academic year for School B
        $academicYearB = AcademicYear::where('school_id', $this->schoolB->id)->first();

        // Create class for School B
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Class 10A',
            'grade_level' => 10,
            'academic_year_id' => $academicYearB->id,
            'max_students' => 30,
            'is_active' => true,
        ]);

        // Create schedule for School B
        $scheduleB = Schedule::create([
            'school_id' => $this->schoolB->id,
            'class_id' => $classB->id,
            'teacher_id' => $this->teacherB->id,
            'academic_year_id' => $academicYearB->id,
            'day_of_week' => 1,
            'start_time' => '08:00',
            'end_time' => '09:30',
        ]);

        // Teacher A tries to view Schedule B
        $this->actingAs($this->teacherA, 'sanctum');

        $canView = $this->teacherA->can('view', $scheduleB);

        $this->assertFalse($canView, 'Teacher A should not be able to view Schedule B from different school');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function super_admin_can_access_any_school_student()
    {
        // Create super admin
        $superAdmin = User::create([
            'username' => 'superadmin',
            'name' => 'Super Admin',
            'email' => 'super@admin.com',
            'password' => bcrypt('password'),
            'role_type' => 'super_admin',
            'is_active' => true,
        ]);

        // Super admin can access Student B
        $this->actingAs($superAdmin, 'sanctum');

        $canView = $superAdmin->can('view', $this->studentB);

        $this->assertTrue($canView, 'Super admin should be able to view any student');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_cannot_access_other_students_from_same_school()
    {
        // Create another student in School A
        $studentA2 = User::create([
            'school_id' => $this->schoolA->id,
            'username' => 'student_a2',
            'name' => 'Student A2',
            'email' => 'student.a2@test.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Student A tries to view Student A2 (same school but different student)
        $this->actingAs($this->studentA, 'sanctum');

        $canView = $this->studentA->can('view', $studentA2);

        $this->assertFalse($canView, 'Student should not be able to view other students');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function student_can_only_view_own_profile()
    {
        // Student A views own profile
        $this->actingAs($this->studentA, 'sanctum');

        $canView = $this->studentA->can('view', $this->studentA);

        $this->assertTrue($canView, 'Student should be able to view own profile');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function policy_prevents_cross_tenant_access_even_with_direct_model_query()
    {
        // Admin A tries to directly access Student B via policy
        $this->actingAs($this->adminA, 'sanctum');

        try {
            \Illuminate\Support\Facades\Gate::authorize('view', $this->studentB);
            $this->fail('Expected AuthorizationException was not thrown');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            $this->assertTrue(true, 'Policy correctly blocked cross-tenant access');
        }
    }
}
