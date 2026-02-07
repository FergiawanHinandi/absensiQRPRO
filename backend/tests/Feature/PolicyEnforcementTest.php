<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Multi-Tenant Policy Enforcement Tests
 *
 * These tests verify that policies block cross-school access
 * even when global scopes are bypassed.
 */
class PolicyEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private School $schoolA;

    private School $schoolB;

    private User $adminA;

    private User $adminB;

    private User $studentA;

    private User $studentB;

    private User $teacherA;

    private User $teacherB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two separate schools
        $this->schoolA = School::create([
            'name' => 'School A',
            'npsn' => '12345678',
            'school_level' => 'SMA',
            'address' => 'Address A',
            'is_active' => true,
        ]);

        $this->schoolB = School::create([
            'name' => 'School B',
            'npsn' => '87654321',
            'school_level' => 'SMA',
            'address' => 'Address B',
            'is_active' => true,
        ]);

        // Create academic years for both schools
        $academicYearA = \App\Models\AcademicYear::create([
            'school_id' => $this->schoolA->id,
            'name' => '2025/2026',
            'semester' => 1,
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
            'is_active' => true,
        ]);

        $academicYearB = \App\Models\AcademicYear::create([
            'school_id' => $this->schoolB->id,
            'name' => '2025/2026',
            'semester' => 1,
            'start_date' => '2025-07-01',
            'end_date' => '2026-06-30',
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

        $this->studentA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Student A',
            'username' => 'student_a',
            'email' => 'student.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
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

        $this->teacherB = User::create([
            'school_id' => $this->schoolB->id,
            'name' => 'Teacher B',
            'username' => 'teacher_b',
            'email' => 'teacher.b@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'teacher',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function user_from_school_a_cannot_view_student_from_school_b_via_policy()
    {
        $this->actingAs($this->adminA, 'sanctum');

        // Try to view student from School B
        $canView = $this->adminA->can('view', $this->studentB);

        $this->assertFalse($canView, 'Admin from School A should NOT be able to view student from School B');
    }

    /** @test */
    public function user_from_school_a_cannot_update_student_from_school_b_via_policy()
    {
        $this->actingAs($this->adminA, 'sanctum');

        $canUpdate = $this->adminA->can('update', $this->studentB);

        $this->assertFalse($canUpdate, 'Admin from School A should NOT be able to update student from School B');
    }

    /** @test */
    public function user_from_school_a_cannot_delete_student_from_school_b_via_policy()
    {
        $this->actingAs($this->adminA, 'sanctum');

        $canDelete = $this->adminA->can('delete', $this->studentB);

        $this->assertFalse($canDelete, 'Admin from School A should NOT be able to delete student from School B');
    }

    /** @test */
    public function attendance_policy_blocks_cross_school_access()
    {
        // Create class and schedule for School B
        $academicYearB = \App\Models\AcademicYear::where('school_id', $this->schoolB->id)->first();
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'academic_year_id' => $academicYearB->id,
            'name' => 'Class B',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        $scheduleB = Schedule::create([
            'school_id' => $this->schoolB->id,
            'class_id' => $classB->id,
            'teacher_id' => $this->teacherB->id,
            'subject_id' => 1,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'is_active' => true,
        ]);

        // Create attendance for student in School B
        $attendanceB = Attendance::create([
            'school_id' => $this->schoolB->id,
            'schedule_id' => $scheduleB->id,
            'student_id' => $this->studentB->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'is_manual' => false,
        ]);

        // User from School A tries to access
        $this->actingAs($this->adminA, 'sanctum');

        $canView = $this->adminA->can('view', $attendanceB);
        $canUpdate = $this->adminA->can('update', $attendanceB);
        $canDelete = $this->adminA->can('delete', $attendanceB);

        $this->assertFalse($canView, 'Admin from School A should NOT view attendance from School B');
        $this->assertFalse($canUpdate, 'Admin from School A should NOT update attendance from School B');
        $this->assertFalse($canDelete, 'Admin from School A should NOT delete attendance from School B');
    }

    /** @test */
    public function class_policy_blocks_cross_school_access()
    {
        $academicYearB = \App\Models\AcademicYear::where('school_id', $this->schoolB->id)->first();
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'academic_year_id' => $academicYearB->id,
            'name' => 'Class B',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        $this->actingAs($this->adminA, 'sanctum');

        $canView = $this->adminA->can('view', $classB);
        $canUpdate = $this->adminA->can('update', $classB);
        $canDelete = $this->adminA->can('delete', $classB);

        $this->assertFalse($canView, 'Admin from School A should NOT view class from School B');
        $this->assertFalse($canUpdate, 'Admin from School A should NOT update class from School B');
        $this->assertFalse($canDelete, 'Admin from School A should NOT delete class from School B');
    }

    /** @test */
    public function schedule_policy_blocks_cross_school_access()
    {
        $academicYearB = \App\Models\AcademicYear::where('school_id', $this->schoolB->id)->first();
        $classB = ClassModel::create([
            'school_id' => $this->schoolB->id,
            'academic_year_id' => $academicYearB->id,
            'name' => 'Class B',
            'grade_level' => 10,
            'is_active' => true,
        ]);

        $scheduleB = Schedule::create([
            'school_id' => $this->schoolB->id,
            'class_id' => $classB->id,
            'teacher_id' => $this->teacherB->id,
            'subject_id' => 1,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
            'is_active' => true,
        ]);

        $this->actingAs($this->teacherA, 'sanctum');

        $canView = $this->teacherA->can('view', $scheduleB);
        $canUpdate = $this->teacherA->can('update', $scheduleB);
        $canDelete = $this->teacherA->can('delete', $scheduleB);

        $this->assertFalse($canView, 'Teacher from School A should NOT view schedule from School B');
        $this->assertFalse($canUpdate, 'Teacher from School A should NOT update schedule from School B');
        $this->assertFalse($canDelete, 'Teacher from School A should NOT delete schedule from School B');
    }

    /** @test */
    public function student_can_only_view_their_own_user_record()
    {
        $this->actingAs($this->studentA, 'sanctum');

        // Can view own record
        $canViewSelf = $this->studentA->can('view', $this->studentA);
        $this->assertTrue($canViewSelf, 'Student should be able to view their own record');

        // Cannot view another student in same school
        $anotherStudentA = User::create([
            'school_id' => $this->schoolA->id,
            'name' => 'Another Student A',
            'username' => 'another_student_a',
            'email' => 'another.student.a@school.com',
            'password' => bcrypt('password'),
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $canViewOther = $this->studentA->can('view', $anotherStudentA);
        $this->assertFalse($canViewOther, 'Student should NOT view another student even in same school');

        // Cannot view student from different school
        $canViewSchoolB = $this->studentA->can('view', $this->studentB);
        $this->assertFalse($canViewSchoolB, 'Student should NOT view student from different school');
    }

    /** @test */
    public function admin_can_view_users_in_same_school_only()
    {
        $this->actingAs($this->adminA, 'sanctum');

        // Can view users in same school
        $canViewStudentA = $this->adminA->can('view', $this->studentA);
        $canViewTeacherA = $this->adminA->can('view', $this->teacherA);

        $this->assertTrue($canViewStudentA, 'Admin should view student in same school');
        $this->assertTrue($canViewTeacherA, 'Admin should view teacher in same school');

        // Cannot view users in different school
        $canViewStudentB = $this->adminA->can('view', $this->studentB);
        $canViewTeacherB = $this->adminA->can('view', $this->teacherB);

        $this->assertFalse($canViewStudentB, 'Admin should NOT view student from different school');
        $this->assertFalse($canViewTeacherB, 'Admin should NOT view teacher from different school');
    }

    /** @test */
    public function policy_blocks_access_even_with_direct_model_retrieval()
    {
        // Simulate bypassing global scope by getting model directly
        $attendanceB = DB::table('attendances')->insertGetId([
            'school_id' => $this->schoolB->id,
            'schedule_id' => 1,
            'student_id' => $this->studentB->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'is_manual' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Get the model without global scope
        $attendance = Attendance::withoutGlobalScope(\App\Scopes\SchoolScope::class)
            ->find($attendanceB);

        $this->assertNotNull($attendance, 'Should be able to retrieve model without scope');

        // But policy should still block access
        $this->actingAs($this->adminA, 'sanctum');

        $canView = $this->adminA->can('view', $attendance);

        $this->assertFalse($canView, 'Policy should block access even when global scope is bypassed');
    }
}
