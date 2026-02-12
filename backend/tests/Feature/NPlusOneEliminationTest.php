ion, got {$reductionPercentage}%. " .
            "Baseline: {$baselineQueryCount}, Actual: {$queryCount}"
        );
    }
}

        $response->assertOk();

        // Without eager loading, this would be: 1 + 1 + 20 + 20 + 20 = 62 queries
        // With eager loading: should be ≤6 queries
        // Reduction: (62 - 6) / 62 = 90.3% reduction
        
        $baselineQueryCount = 62; // Estimated baseline without eager loading
        $reductionPercentage = (($baselineQueryCount - $queryCount) / $baselineQueryCount) * 100;

        $this->assertGreaterThanOrEqual(80, $reductionPercentage,
            "Expected ≥80% query reduct 'active',
            ]);

            Attendance::factory()->count(3)->create([
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
                'school_id' => $this->school->id,
            ]);
        }

        DB::enableQueryLog();
        
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/attendance/class/{$this->schedule->id}");

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
*/
    public function test_overall_query_reduction_meets_target(): void
    {
        // Create realistic data set
        $students = User::factory()->count(20)->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'class_id' => $this->class->id,
        ]);

        foreach ($students as $student) {
            ClassStudent::create([
                'student_id' => $student->id,
                'class_id' => $this->class->id,
                'status' =>is->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/student/dashboard/history');
        $queryCount50 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Query count should be the same regardless of data size (no N+1)
        $this->assertEquals($queryCount10, $queryCount50,
            "Query count increased from {$queryCount10} to {$queryCount50}. N+1 pattern detected."
        );
    }

    /**
     * Test 12: Overall query reduction verification (80%+ reduction)
     >actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/student/dashboard/history');
        $queryCount10 = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Clear and test with 50 records
        AttendanceLog::query()->delete();
        AttendanceLog::factory()->count(50)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
        ]);

        DB::enableQueryLog();
        $thyCount}. N+1 detected in attendance reports."
        );
    }

    /**
     * Test 11: Query count scales linearly, not exponentially
     */
    public function test_query_count_scales_linearly_with_data_size(): void
    {
        // Test with 10 records
        AttendanceLog::factory()->count(10)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
        ]);

        DB::enableQueryLog();
        $this-_id' => $this->school->id,
        ]);

        DB::enableQueryLog();
        
        $response = $this->actingAs($this->teacher, 'sanctum')
            ->getJson('/api/v1/attendance-reports');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 attendances + 1 students + 1 schedules + 1 subjects = 5 queries max
        $this->assertLessThanOrEqual(5, $queryCount,
            "Expected ≤5 queries, got {$quernOrEqual(5, $queryCount,
            "Expected ≤5 queries, got {$queryCount}. N+1 detected in teacher assignments."
        );
    }

    /**
     * Test 10: AttendanceReportController::index() - Should use ≤5 queries
     */
    public function test_attendance_report_list_has_minimal_queries(): void
    {
        // Create 20 attendance records
        Attendance::factory()->count(20)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'schools_id' => $this->class->id,
                'school_id' => $this->school->id,
            ]);
        }

        DB::enableQueryLog();
        
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/school-admin/teacher-assignments');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 assignments + 1 teachers + 1 subjects + 1 classes = 5 queries max
        $this->assertLessTha    'school_id' => $this->school->id,
            'role_type' => 'school_admin',
        ]);

        // Create teacher subjects (assignments)
        $teachers = User::factory()->count(10)->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        foreach ($teachers as $teacher) {
            \App\Models\TeacherSubject::factory()->create([
                'teacher_id' => $teacher->id,
                'subject_id' => $this->subject->id,
                'clasesponse->assertOk();
        
        // Should use: 1 auth + 1 students + 1 class_students + 1 pagination count = 4 queries max
        $this->assertLessThanOrEqual(4, $queryCount,
            "Expected ≤4 queries, got {$queryCount}. N+1 detected in admin student list."
        );
    }

    /**
     * Test 9: SchoolAdmin/TeacherController::assignments() - Should use ≤5 queries
     */
    public function test_teacher_assignments_has_eager_loading(): void
    {
        $admin = User::factory()->create([
        d' => $this->class->id,
        ]);

        foreach ($students as $student) {
            ClassStudent::create([
                'student_id' => $student->id,
                'class_id' => $this->class->id,
                'status' => 'active',
            ]);
        }

        DB::enableQueryLog();
        
        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/school-admin/students');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $r   /**
     * Test 8: SchoolAdmin/StudentController::index() - Should use ≤4 queries
     */
    public function test_school_admin_student_list_has_minimal_queries(): void
    {
        $admin = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'school_admin',
        ]);

        // Create 30 students
        $students = User::factory()->count(30)->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'class_i>actingAs($this->teacher, 'sanctum')
            ->getJson("/api/v1/teacher/dashboard/students/{$this->class->id}");

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 students + 1 class_students + 1 batch attendances + 1 class = 5 queries max
        $this->assertLessThanOrEqual(5, $queryCount,
            "Expected ≤5 queries, got {$queryCount}. N+1 detected in teacher students list."
        );
    }

    ClassStudent::create([
                'student_id' => $student->id,
                'class_id' => $this->class->id,
                'status' => 'active',
            ]);

            // Create attendance records
            Attendance::factory()->count(5)->create([
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
                'school_id' => $this->school->id,
            ]);
        }

        DB::enableQueryLog();
        
        $response = $this- detected in parent dashboard."
        );
    }

    /**
     * Test 7: TeacherDashboardController::myStudents() - Should use ≤5 queries
     */
    public function test_teacher_my_students_uses_batch_loading(): void
    {
        // Create 25 students in the class
        $students = User::factory()->count(25)->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'class_id' => $this->class->id,
        ]);

        foreach ($students as $student) {
          ]);
        }

        DB::enableQueryLog();
        
        $response = $this->actingAs($this->parent, 'sanctum')
            ->getJson('/api/v1/parent/dashboard');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 children + 1 profiles + 1 class_students + 1 classes + 1 attendances = 6 queries max
        $this->assertLessThanOrEqual(6, $queryCount,
            "Expected ≤6 queries, got {$queryCount}. N+1 foreach ($children as $child) {
            $this->parent->children()->attach($child->id, ['relationship' => 'mother']);
            
            ClassStudent::create([
                'student_id' => $child->id,
                'class_id' => $this->class->id,
                'status' => 'active',
            ]);

            Attendance::factory()->create([
                'student_id' => $child->id,
                'schedule_id' => $this->schedule->id,
                'school_id' => $this->school->id,
           
            "Expected ≤4 queries, got {$queryCount}. N+1 detected in student profile."
        );
    }

    /**
     * Test 6: ParentDashboardController::index() - Should use ≤6 queries
     */
    public function test_parent_dashboard_has_minimal_queries(): void
    {
        // Add more children to test N+1
        $children = User::factory()->count(3)->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'class_id' => $this->class->id,
        ]);

       se ≤4 queries
     */
    public function test_student_profile_uses_eloquent_with_eager_loading(): void
    {
        DB::enableQueryLog();
        
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/student/dashboard/profile');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 user + 1 class + 1 school = 4 queries max
        $this->assertLessThanOrEqual(4, $queryCount,ctum')
            ->getJson('/api/v1/student/dashboard/schedule');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 schedules + 1 class + 1 subject + 1 teacher = 5 queries max
        $this->assertLessThanOrEqual(5, $queryCount,
            "Expected ≤5 queries, got {$queryCount}. N+1 detected in student schedule."
        );
    }

    /**
     * Test 5: StudentDashboardController::profile() - Should ublic function test_student_schedule_uses_eloquent_with_eager_loading(): void
    {
        // Create 8 schedules for today
        Schedule::factory()->count(8)->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_id' => $this->subject->id,
            'teacher_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
        ]);

        DB::enableQueryLog();
        
        $response = $this->actingAs($this->student, 'sanetJson('/api/v1/student/dashboard/history');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 logs + 1 schedule + 1 subject + 1 teacher = 5 queries max
        $this->assertLessThanOrEqual(5, $queryCount,
            "Expected ≤5 queries, got {$queryCount}. N+1 detected in student history."
        );
    }

    /**
     * Test 4: StudentDashboardController::schedule() - Should use ≤5 queries
     */
    puDashboardController::history() - Should use ≤5 queries
     */
    public function test_student_history_uses_eloquent_with_eager_loading(): void
    {
        // Create 15 attendance logs
        AttendanceLog::factory()->count(15)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school->id,
        ]);

        DB::enableQueryLog();
        
        $response = $this->actingAs($this->student, 'sanctum')
            ->ghis->teacher, 'sanctum')
            ->getJson("/api/v1/attendance/class/{$this->schedule->id}");

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 schedule + 1 class + 1 students + 1 attendances + 1 subject = 6 queries max
        $this->assertLessThanOrEqual(6, $queryCount,
            "Expected ≤6 queries, got {$queryCount}. N+1 detected in class attendance."
        );
    }

    /**
     * Test 3: Studenteate([
                'student_id' => $student->id,
                'class_id' => $this->class->id,
                'status' => 'active',
            ]);

            Attendance::factory()->create([
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
                'school_id' => $this->school->id,
                'attendance_date' => now()->toDateString(),
            ]);
        }

        DB::enableQueryLog();
        
        $response = $this->actingAs($tendance history."
        );
    }

    /**
     * Test 2: AttendanceController::classAttendance() - Should use ≤6 queries
     */
    public function test_class_attendance_has_minimal_queries(): void
    {
        // Create 20 students in the class
        $students = User::factory()->count(20)->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'class_id' => $this->class->id,
        ]);

        foreach ($students as $student) {
            ClassStudent::cr>id,
        ]);

        DB::enableQueryLog();
        
        $response = $this->actingAs($this->student, 'sanctum')
            ->getJson('/api/v1/attendance/history');

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        
        // Should use: 1 auth + 1 attendance + 1 schedule + 1 subject + 1 teacher = 5 queries max
        $this->assertLessThanOrEqual(5, $queryCount, 
            "Expected ≤5 queries, got {$queryCount}. N+1 detected in atther_id' => $this->teacher->id,
            'day_of_week' => now()->dayOfWeek,
        ]);
    }

    /**
     * Test 1: AttendanceController::history() - Should use ≤5 queries
     */
    public function test_attendance_history_has_minimal_queries(): void
    {
        // Create 10 attendance records with relationships
        AttendanceLog::factory()->count(10)->create([
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'school_id' => $this->school-d' => $this->subject->id,
            'teacass_id' => $this->class->id,
            'status' => 'active',
        ]);

        $this->parent = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'parent',
        ]);

        // Link parent to student
        $this->parent->children()->attach($this->student->id, ['relationship' => 'father']);

        $this->schedule = Schedule::factory()->create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'subject_i   ]);

        ClassStudent::create([
            'student_id' => $this->student->id,
            'cl' => 'Test Class',
        ]);

        $this->subject = Subject::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'Mathematics',
        ]);

        $this->teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
            'class_id' => $this->class->id,
     ClassModel $class;
    private Subject $subject;
    private Schedule $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->school = School::factory()->create();
        
        $this->class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
            'name
 * - Query count reduced by 80%+ from baseline
 * - No N+1 patterns in high-traffic endpoints
 * - Eager loading with field selection implemented
 */
class NPlusOneEliminationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;
    private User $student;
    private User $teacher;
    private User $parent;
    private riteria:;
use App\Models\AttendanceLog;
use App\Models\ClassModel;
use App\Models\ClassStudent;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * N+1 Query Elimination Tests
 * 
 * Validates: Requirements 12.1, 12.2
 * 
 * These tests verify that eager loading has been properly implemented
 * to eliminate N+1 query problems across critical endpoints.
 * 
 * Success C<?php

namespace Tests\Feature;

use App\Models\Attendance