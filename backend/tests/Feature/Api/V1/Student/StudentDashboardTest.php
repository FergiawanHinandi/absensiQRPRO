<?php

namespace Tests\Feature\Api\V1\Student;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StudentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $otherStudent;

    private School $school;

    private ClassModel $class;

    protected function setUp(): void
    {
        parent::setUp();

        // Create school
        $this->school = School::factory()->create([
            'name' => 'SMA Negeri 1',
        ]);

        // Create class
        $this->class = ClassModel::create([
            'school_id' => $this->school->id,
            'name' => 'X-A',
            'grade_level' => '10',
            'capacity' => 30,
            'is_active' => true,
        ]);

        // Create authenticated student
        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'name' => 'Ahmad Rizki',
            'email' => 'ahmad@student.test',
            'is_active' => true,
        ]);

        // Assign student to class
        ClassStudent::create([
            'class_id' => $this->class->id,
            'student_id' => $this->student->id,
            'status' => 'active',
        ]);

        // Create another student for access control testing
        $this->otherStudent = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'name' => 'Budi Santoso',
            'email' => 'budi@student.test',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // TEST: Dashboard Access
    // =========================================================================

    /** @test */
    public function student_can_access_own_dashboard()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'student_info',
                    'today_attendance',
                    'streak_info',
                    'recent_activities',
                ],
            ]);
    }

    /** @test */
    public function guest_cannot_access_student_dashboard()
    {
        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(401);
    }

    /** @test */
    public function non_student_cannot_access_student_dashboard()
    {
        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        Sanctum::actingAs($teacher);

        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(403);
    }

    /** @test */
    public function dashboard_shows_today_status()
    {
        Sanctum::actingAs($this->student);

        // Create today's attendance
        $today = Carbon::now()->toDateString();
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => $today,
            'status' => 'present',
            'check_in_time' => '07:30:00',
        ]);

        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'today_attendance' => [
                        'date' => $today,
                        'status' => 'present',
                        'has_checked_in' => true,
                    ],
                ],
            ]);
    }

    /** @test */
    public function dashboard_shows_not_checked_in_when_no_attendance_today()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'today_attendance' => [
                        'has_checked_in' => false,
                        'status' => null,
                    ],
                ],
            ]);
    }

    /** @test */
    public function dashboard_includes_student_streak_information()
    {
        Sanctum::actingAs($this->student);

        // Update student streak
        $this->student->update([
            'current_streak' => 15,
            'longest_streak' => 30,
            'total_points' => 450,
        ]);

        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'streak_info' => [
                        'current_streak' => 15,
                        'longest_streak' => 30,
                        'total_points' => 450,
                    ],
                ],
            ]);
    }

    // =========================================================================
    // TEST: Attendance History
    // =========================================================================

    /** @test */
    public function student_can_view_attendance_history()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'records',
                    'summary',
                    'period',
                ],
            ]);
    }

    /** @test */
    public function history_returns_last_30_days_only()
    {
        Sanctum::actingAs($this->student);

        // Create attendance records spanning 45 days
        $today = Carbon::now();

        // Records within 30 days (should be included)
        for ($i = 0; $i < 25; $i++) {
            Attendance::create([
                'school_id' => $this->school->id,
                'student_id' => $this->student->id,
                'attendance_date' => $today->copy()->subDays($i)->toDateString(),
                'status' => 'present',
                'check_in_time' => '07:30:00',
            ]);
        }

        // Records older than 30 days (should NOT be included)
        for ($i = 31; $i < 45; $i++) {
            Attendance::create([
                'school_id' => $this->school->id,
                'student_id' => $this->student->id,
                'attendance_date' => $today->copy()->subDays($i)->toDateString(),
                'status' => 'present',
                'check_in_time' => '07:30:00',
            ]);
        }

        $response = $this->getJson('/api/v1/student/history');

        $response->assertStatus(200);

        $records = $response->json('data.records');

        // Should return only records from last 30 days (25 records)
        $this->assertCount(25, $records);

        // Verify all records are within 30 days
        $thirtyDaysAgo = $today->copy()->subDays(30)->toDateString();
        foreach ($records as $record) {
            $this->assertGreaterThanOrEqual(
                $thirtyDaysAgo,
                $record['attendance_date']
            );
        }
    }

    /** @test */
    public function student_cannot_view_other_student_history()
    {
        // Create attendance for other student
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->otherStudent->id,
            'attendance_date' => Carbon::now()->toDateString(),
            'status' => 'present',
            'check_in_time' => '07:30:00',
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/history');

        $response->assertStatus(200);

        $records = $response->json('data.records');

        // Should NOT include other student's data
        foreach ($records as $record) {
            $this->assertEquals($this->student->id, $record['student_id']);
            $this->assertNotEquals($this->otherStudent->id, $record['student_id']);
        }
    }

    /** @test */
    public function history_includes_attendance_summary()
    {
        Sanctum::actingAs($this->student);

        $today = Carbon::now();

        // Create mixed attendance records
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => $today->copy()->subDays(1)->toDateString(),
            'status' => 'present',
        ]);

        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => $today->copy()->subDays(2)->toDateString(),
            'status' => 'late',
        ]);

        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => $today->copy()->subDays(3)->toDateString(),
            'status' => 'absent',
        ]);

        $response = $this->getJson('/api/v1/student/history');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'summary' => [
                        'total_days',
                        'present_count',
                        'late_count',
                        'absent_count',
                        'attendance_rate',
                    ],
                ],
            ]);

        $summary = $response->json('data.summary');
        $this->assertEquals(3, $summary['total_days']);
        $this->assertEquals(1, $summary['present_count']);
        $this->assertEquals(1, $summary['late_count']);
        $this->assertEquals(1, $summary['absent_count']);
    }

    // =========================================================================
    // TEST: Schedule
    // =========================================================================

    /** @test */
    public function student_can_view_schedule()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/schedule');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'today_schedule',
                    'date',
                ],
            ]);
    }

    /** @test */
    public function schedule_returns_only_today_classes()
    {
        Sanctum::actingAs($this->student);

        $subject1 = Subject::create([
            'school_id' => $this->school->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
        ]);

        $subject2 = Subject::create([
            'school_id' => $this->school->id,
            'name' => 'Physics',
            'code' => 'PHY',
        ]);

        $today = Carbon::now();
        $tomorrow = Carbon::now()->addDay();

        // Today's schedule
        Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $subject1->id,
            'day_of_week' => $today->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        // Tomorrow's schedule (should NOT be included)
        Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $subject2->id,
            'day_of_week' => $tomorrow->dayOfWeek,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $response = $this->getJson('/api/v1/student/schedule');

        $response->assertStatus(200);

        $schedules = $response->json('data.today_schedule');

        // Should only include today's classes
        $this->assertCount(1, $schedules);
        $this->assertEquals('Mathematics', $schedules[0]['subject_name']);
        $this->assertEquals('08:00:00', $schedules[0]['start_time']);
    }

    /** @test */
    public function schedule_returns_empty_array_when_no_classes_today()
    {
        Sanctum::actingAs($this->student);

        $tomorrow = Carbon::now()->addDay();
        $subject = Subject::create([
            'school_id' => $this->school->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
        ]);

        // Only tomorrow's schedule
        Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $subject->id,
            'day_of_week' => $tomorrow->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        $response = $this->getJson('/api/v1/student/schedule');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'today_schedule' => [],
                ],
            ]);
    }

    /** @test */
    public function student_cannot_view_other_class_schedule()
    {
        Sanctum::actingAs($this->student);

        $otherClass = ClassModel::create([
            'school_id' => $this->school->id,
            'name' => 'X-B',
            'grade_level' => '10',
            'capacity' => 30,
            'is_active' => true,
        ]);

        $subject = Subject::create([
            'school_id' => $this->school->id,
            'name' => 'Mathematics',
            'code' => 'MATH',
        ]);

        $today = Carbon::now();

        // Schedule for student's class (should be included)
        Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $subject->id,
            'day_of_week' => $today->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);

        // Schedule for other class (should NOT be included)
        Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $otherClass->id,
            'subject_id' => $subject->id,
            'day_of_week' => $today->dayOfWeek,
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
        ]);

        $response = $this->getJson('/api/v1/student/schedule');

        $response->assertStatus(200);

        $schedules = $response->json('data.today_schedule');

        // Should only include own class schedule
        $this->assertCount(1, $schedules);
        $this->assertEquals('08:00:00', $schedules[0]['start_time']);
    }

    // =========================================================================
    // TEST: Profile
    // =========================================================================

    /** @test */
    public function student_can_view_own_profile()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'class_info',
                    'school_info',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => $this->student->id,
                    'name' => 'Ahmad Rizki',
                    'email' => 'ahmad@student.test',
                ],
            ]);
    }

    /** @test */
    public function student_cannot_access_other_student_profile_directly()
    {
        Sanctum::actingAs($this->student);

        // Try to access other student's profile (if such endpoint exists)
        $response = $this->getJson("/api/v1/student/profile/{$this->otherStudent->id}");

        // Should either return 403 or 404
        $this->assertContains($response->status(), [403, 404]);
    }

    /** @test */
    public function profile_includes_class_information()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/profile');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'class_info' => [
                        'class_name',
                        'grade_level',
                    ],
                ],
            ])
            ->assertJson([
                'data' => [
                    'class_info' => [
                        'class_name' => 'X-A',
                        'grade_level' => '10',
                    ],
                ],
            ]);
    }

    /** @test */
    public function profile_includes_school_information()
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/profile');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'school_info' => [
                        'school_id' => $this->school->id,
                        'school_name' => 'SMA Negeri 1',
                    ],
                ],
            ]);
    }

    // =========================================================================
    // TEST: Data Isolation & Security
    // =========================================================================

    /** @test */
    public function student_only_sees_own_school_data()
    {
        // Create another school with student
        $otherSchool = School::factory()->create(['name' => 'SMA Negeri 2']);
        $studentFromOtherSchool = User::factory()->create([
            'school_id' => $otherSchool->id,
            'role_type' => 'student',
        ]);

        // Create attendance for both schools
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'attendance_date' => Carbon::now()->toDateString(),
            'status' => 'present',
        ]);

        Attendance::create([
            'school_id' => $otherSchool->id,
            'student_id' => $studentFromOtherSchool->id,
            'attendance_date' => Carbon::now()->toDateString(),
            'status' => 'present',
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/history');

        $response->assertStatus(200);

        $records = $response->json('data.records');

        // Should only see own school's data
        foreach ($records as $record) {
            $this->assertEquals($this->school->id, $record['school_id']);
        }
    }

    /** @test */
    public function inactive_student_cannot_access_dashboard()
    {
        $this->student->update(['is_active' => false]);

        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/v1/student/dashboard');

        $response->assertStatus(403);
    }
}
