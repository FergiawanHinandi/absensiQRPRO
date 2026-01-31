<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Schedule;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FinalPerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test Scenario 1: 500 scans concurrently (simulated)
     * Expected: No duplicates allowed by database
     */
    public function test_concurrent_scans_prevent_duplicates()
    {
        // Setup
        $school = School::factory()->create();

        // Create Class
        $class = \App\Models\ClassModel::factory()->create([
            'school_id' => $school->id,
        ]);

        $student = User::factory()->create([
            'role_type' => 'student',
            'school_id' => $school->id,
            'is_active' => true,
        ]);

        // Attach student to class
        DB::table('class_students')->insert([
            'class_id' => $class->id,
            'student_id' => $student->id,
            'enrollment_date' => now(),
            'status' => 'active',
        ]);

        // Create Academic Year for Schedule
        $academicYear = \App\Models\AcademicYear::factory()->create([
            'school_id' => $school->id,
            'is_active' => true,
        ]);

        $schedule = Schedule::factory()->create([
            'school_id' => $school->id,
            'class_id' => $class->id,
            'academic_year_id' => $academicYear->id,
        ]);

        $attendanceDate = now()->toDateString();

        // Try to insert same record twice "concurrently"
        // 1. First "Scan"
        Attendance::create([
            'school_id' => $school->id,
            'schedule_id' => $schedule->id,
            'student_id' => $student->id,
            'attendance_date' => $attendanceDate,
            'status' => 'present',
            'check_in_time' => now(),
            'request_id' => \Illuminate\Support\Str::uuid(),
        ]);

        // 2. Second "Scan" - Should fail due to duplicate entry
        $this->expectException(\Illuminate\Database\QueryException::class);
        // $this->expectExceptionMessage('Integrity constraint violation');

        Attendance::create([
            'school_id' => $school->id,
            'schedule_id' => $schedule->id,
            'student_id' => $student->id,
            'attendance_date' => $attendanceDate,
            'status' => 'present',
            'check_in_time' => now(),
            'request_id' => \Illuminate\Support\Str::uuid(),
        ]);
    }

    /**
     * Test Scenario 2: Dashboard Update resets cache
     */
    public function test_dashboard_cache_resets_after_attendance()
    {
        $school = School::factory()->create();
        // Ensure schedule belongs to same school
        $schedule = Schedule::factory()->create(['school_id' => $school->id]);

        // Ensure student belongs to same school (and ideally class, but for this test checking cache, simpler is fine)
        $student = User::factory()->create(['role_type' => 'student', 'school_id' => $school->id]);

        // 1. Simulate Dashboard Access (Cache Warmup)
        // We can manually put something in cache tags if we can't call API fully
        $cacheKey = "attendance_daily_report_{$school->id}_".now()->toDateString();
        $cacheTags = ['dashboard', "school_{$school->id}"];

        Cache::tags($cacheTags)->put($cacheKey, 'stale_data', 600);

        $this->assertEquals('stale_data', Cache::tags($cacheTags)->get($cacheKey));

        // 2. Perform Attendance
        // Trigger event manually as Controller would
        $attendance = Attendance::create([
            'school_id' => $school->id,
            'schedule_id' => $schedule->id,
            'student_id' => $student->id,
            'attendance_date' => now()->toDateString(),
            'status' => 'present',
            'check_in_time' => now(),
            'request_id' => \Illuminate\Support\Str::uuid(),
        ]);

        // Dispatch Event
        \App\Events\StudentAttended::dispatch($attendance, $school->id);

        // 3. Check Cache
        // It should be gone (null)
        $this->assertNull(Cache::tags($cacheTags)->get($cacheKey));
    }

    /**
     * Test Scenario 3: N+1 Query Check for Dashboard
     *
     * We simulate fetching dashboard data and count queries.
     * Note: This requires the actual controller logic execution.
     */
    public function test_admin_dashboard_query_count_is_stable()
    {
        $school = School::factory()->create();
        $admin = User::factory()->create(['role_type' => 'school_admin', 'school_id' => $school->id]);

        $class = \App\Models\ClassModel::factory()->create(['school_id' => $school->id]);

        // Seed 50 students
        $students = User::factory()->count(50)->create(['role_type' => 'student', 'school_id' => $school->id]);

        // Attach students to class
        $pivotData = $students->map(function ($student) use ($class) {
            return [
                'class_id' => $class->id,
                'student_id' => $student->id,
                'enrollment_date' => now(),
                'status' => 'active',
            ];
        })->toArray();

        DB::table('class_students')->insert($pivotData);

        $this->actingAs($admin);

        DB::enableQueryLog();

        // Call the daily report API
        $response = $this->getJson('/api/v1/reports/daily');

        $response->assertStatus(200);

        $queryLog = DB::getQueryLog();
        $queryCount = count($queryLog);

        // Expectation: Should be constant low number regardless of student count
        // Usually around 5-10 queries (User count, Attendance counts by status)
        $this->assertLessThan(20, $queryCount, "Queries should be optimized (actual: $queryCount)");
    }
}
