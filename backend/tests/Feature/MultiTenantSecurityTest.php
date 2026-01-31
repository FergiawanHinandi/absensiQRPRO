<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MultiTenantSecurityTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected $school1;

    protected $school2;

    protected $admin1;

    protected $admin2;

    protected $student1;

    protected $student2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two separate schools
        $this->school1 = School::create([
            'name' => 'School 1',
            'npsn' => '12345678',
            'address' => 'Address 1',
            'phone' => '081234567890',
            'email' => 'school1@example.com',
            'principal_name' => 'Principal 1',
            'is_active' => true,
        ]);

        $this->school2 = School::create([
            'name' => 'School 2',
            'npsn' => '87654321',
            'address' => 'Address 2',
            'phone' => '081234567891',
            'email' => 'school2@example.com',
            'principal_name' => 'Principal 2',
            'is_active' => true,
        ]);

        // Create admin for each school
        $this->admin1 = User::create([
            'name' => 'Admin 1',
            'email' => 'admin1@school1.com',
            'username' => 'admin1',
            'password' => bcrypt('password'),
            'school_id' => $this->school1->id,
            'role_type' => 'admin',
            'is_active' => true,
        ]);

        $this->admin2 = User::create([
            'name' => 'Admin 2',
            'email' => 'admin2@school2.com',
            'username' => 'admin2',
            'password' => bcrypt('password'),
            'school_id' => $this->school2->id,
            'role_type' => 'admin',
            'is_active' => true,
        ]);

        // Create student for each school
        $this->student1 = User::create([
            'name' => 'Student 1',
            'email' => 'student1@school1.com',
            'username' => 'student1',
            'password' => bcrypt('password'),
            'school_id' => $this->school1->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        $this->student2 = User::create([
            'name' => 'Student 2',
            'email' => 'student2@school2.com',
            'username' => 'student2',
            'password' => bcrypt('password'),
            'school_id' => $this->school2->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);
    }

    /**
     * Test: Admin from school 1 cannot access student from school 2
     *
     * @return void
     */
    public function test_admin_cannot_access_student_from_different_school()
    {
        Sanctum::actingAs($this->admin1, ['*']);

        // Try to access student from school 2
        $response = $this->getJson("/api/v1/admin/students/{$this->student2->id}");

        // Should be forbidden or not found
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            'Admin should not be able to access student from different school'
        );

        $response->assertJsonMissing(['email' => $this->student2->email]);
    }

    /**
     * Test: Admin can access student from same school
     *
     * @return void
     */
    public function test_admin_can_access_student_from_same_school()
    {
        Sanctum::actingAs($this->admin1, ['*']);

        // Access student from same school
        $response = $this->getJson("/api/v1/admin/students/{$this->student1->id}");

        $response->assertStatus(200);
        $response->assertJsonFragment(['email' => $this->student1->email]);
    }

    /**
     * Test: Manual attendance cannot be created for student from different school
     *
     * @return void
     */
    public function test_manual_attendance_blocked_for_different_school_student()
    {
        Sanctum::actingAs($this->admin1, ['*']);

        // Create class and schedule for school 1
        $class1 = ClassModel::create([
            'name' => 'Class 1A',
            'school_id' => $this->school1->id,
        ]);

        $subject1 = Subject::create([
            'name' => 'Math',
            'code' => 'MATH101',
            'school_id' => $this->school1->id,
        ]);

        $schedule1 = Schedule::create([
            'school_id' => $this->school1->id,
            'class_id' => $class1->id,
            'subject_id' => $subject1->id,
            'teacher_id' => $this->admin1->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        // Try to create manual attendance for student from school 2
        $response = $this->postJson('/api/v1/admin/attendance/manual', [
            'student_id' => $this->student2->id, // Different school!
            'schedule_id' => $schedule1->id,
            'attendance_date' => now()->format('Y-m-d'),
            'status' => 'present',
        ]);

        // Should be rejected
        $this->assertTrue(
            in_array($response->status(), [403, 422, 404]),
            'Manual attendance for different school student should be rejected'
        );

        // Verify no attendance was created
        $this->assertDatabaseMissing('attendances', [
            'student_id' => $this->student2->id,
            'schedule_id' => $schedule1->id,
        ]);
    }

    /**
     * Test: List students endpoint only shows students from same school
     *
     * @return void
     */
    public function test_list_students_only_shows_same_school()
    {
        Sanctum::actingAs($this->admin1, ['*']);

        $response = $this->getJson('/api/v1/admin/students');

        $response->assertStatus(200);

        // Should include student from school 1
        $response->assertJsonFragment(['email' => $this->student1->email]);

        // Should NOT include student from school 2
        $response->assertJsonMissing(['email' => $this->student2->email]);
    }

    /**
     * Test: Teacher cannot access classes from different school
     *
     * @return void
     */
    public function test_teacher_cannot_access_classes_from_different_school()
    {
        $teacher1 = User::create([
            'name' => 'Teacher 1',
            'email' => 'teacher1@school1.com',
            'username' => 'teacher1',
            'password' => bcrypt('password'),
            'school_id' => $this->school1->id,
            'role_type' => 'teacher',
            'is_active' => true,
        ]);

        $class2 = ClassModel::create([
            'name' => 'Class 2A',
            'school_id' => $this->school2->id,
        ]);

        Sanctum::actingAs($teacher1, ['*']);

        $response = $this->getJson("/api/v1/teacher/classes/{$class2->id}");

        // Should be forbidden or not found
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            'Teacher should not access classes from different school'
        );
    }

    /**
     * Test: Attendance query is properly scoped by school
     *
     * @return void
     */
    public function test_attendance_query_scoped_by_school()
    {
        // Create attendance for both schools
        $class1 = ClassModel::create([
            'name' => 'Class 1A',
            'school_id' => $this->school1->id,
        ]);

        $subject1 = Subject::create([
            'name' => 'Math',
            'code' => 'MATH101',
            'school_id' => $this->school1->id,
        ]);

        $schedule1 = Schedule::create([
            'school_id' => $this->school1->id,
            'class_id' => $class1->id,
            'subject_id' => $subject1->id,
            'teacher_id' => $this->admin1->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        $attendance1 = Attendance::create([
            'school_id' => $this->school1->id,
            'student_id' => $this->student1->id,
            'schedule_id' => $schedule1->id,
            'attendance_date' => now()->format('Y-m-d'),
            'status' => 'present',
            'recorded_by' => $this->admin1->id,
        ]);

        $class2 = ClassModel::create([
            'name' => 'Class 2A',
            'school_id' => $this->school2->id,
        ]);

        $subject2 = Subject::create([
            'name' => 'Math',
            'code' => 'MATH201',
            'school_id' => $this->school2->id,
        ]);

        $schedule2 = Schedule::create([
            'school_id' => $this->school2->id,
            'class_id' => $class2->id,
            'subject_id' => $subject2->id,
            'teacher_id' => $this->admin2->id,
            'day_of_week' => 1,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        $attendance2 = Attendance::create([
            'school_id' => $this->school2->id,
            'student_id' => $this->student2->id,
            'schedule_id' => $schedule2->id,
            'attendance_date' => now()->format('Y-m-d'),
            'status' => 'present',
            'recorded_by' => $this->admin2->id,
        ]);

        // Admin 1 queries attendance
        Sanctum::actingAs($this->admin1, ['*']);

        $response = $this->getJson('/api/v1/admin/attendance');

        $response->assertStatus(200);

        // Should only see attendance from school 1
        $data = $response->json('data.data') ?? $response->json('data');

        foreach ($data as $record) {
            $this->assertEquals(
                $this->school1->id,
                $record['school_id'],
                'All attendance records should belong to school 1'
            );
        }
    }

    /**
     * Test: Update student from different school is blocked
     *
     * @return void
     */
    public function test_update_student_from_different_school_blocked()
    {
        Sanctum::actingAs($this->admin1, ['*']);

        $response = $this->putJson("/api/v1/admin/students/{$this->student2->id}", [
            'name' => 'Hacked Name',
            'email' => 'hacked@example.com',
        ]);

        // Should be forbidden
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            'Update student from different school should be blocked'
        );

        // Verify student was not updated
        $this->assertDatabaseHas('users', [
            'id' => $this->student2->id,
            'email' => $this->student2->email,
            'name' => $this->student2->name,
        ]);

        $this->assertDatabaseMissing('users', [
            'id' => $this->student2->id,
            'email' => 'hacked@example.com',
        ]);
    }

    /**
     * Test: Delete student from different school is blocked
     *
     * @return void
     */
    public function test_delete_student_from_different_school_blocked()
    {
        Sanctum::actingAs($this->admin1, ['*']);

        $response = $this->deleteJson("/api/v1/admin/students/{$this->student2->id}");

        // Should be forbidden
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            'Delete student from different school should be blocked'
        );

        // Verify student still exists
        $this->assertDatabaseHas('users', [
            'id' => $this->student2->id,
            'email' => $this->student2->email,
        ]);
    }
}
