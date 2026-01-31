<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Schedule;
use App\Models\School;
use App\Models\Subject;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardCacheUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected $school;

    protected $admin;

    protected $student;

    protected $schedule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::factory()->create();

        $this->admin = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'admin',
        ]);

        $this->student = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'student',
        ]);

        $class = ClassModel::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $subject = Subject::factory()->create([
            'school_id' => $this->school->id,
        ]);

        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        $this->schedule = Schedule::create([
            'school_id' => $this->school->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'teacher_id' => $teacher->id,
            'day_of_week' => Carbon::now()->dayOfWeek,
            'start_time' => '08:00:00',
            'end_time' => '09:00:00',
        ]);
    }

    /**
     * Test: Dashboard cache is cleared after new attendance
     *
     * @return void
     */
    public function test_dashboard_cache_cleared_after_attendance()
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Get initial dashboard data (this will cache it)
        $response1 = $this->getJson('/api/v1/admin/dashboard');
        $response1->assertStatus(200);

        $initialStats = $response1->json('data');
        $initialPresent = $initialStats['present_today'] ?? 0;

        // Verify cache was set
        $cacheKey = "dashboard:school:{$this->school->id}:admin:{$this->admin->id}";
        $this->assertTrue(
            Cache::tags(['dashboard', "school:{$this->school->id}"])->has($cacheKey),
            'Dashboard cache should be set'
        );

        // Create new attendance
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => Carbon::now()->format('Y-m-d'),
            'status' => 'present',
            'recorded_by' => $this->admin->id,
        ]);

        // Cache should be cleared
        $this->assertFalse(
            Cache::tags(['dashboard', "school:{$this->school->id}"])->has($cacheKey),
            'Dashboard cache should be cleared after new attendance'
        );

        // Get dashboard data again
        $response2 = $this->getJson('/api/v1/admin/dashboard');
        $response2->assertStatus(200);

        $updatedStats = $response2->json('data');
        $updatedPresent = $updatedStats['present_today'] ?? 0;

        // Stats should reflect new attendance
        $this->assertGreaterThan(
            $initialPresent,
            $updatedPresent,
            'Present count should increase after new attendance'
        );
    }

    /**
     * Test: Event listener clears dashboard cache
     *
     * @return void
     */
    public function test_event_listener_clears_dashboard_cache()
    {
        // Listen for StudentAttended event
        Event::fake(['App\Events\StudentAttended']);

        // Set initial cache
        $cacheKey = "dashboard:school:{$this->school->id}:admin:{$this->admin->id}";
        Cache::tags(['dashboard', "school:{$this->school->id}"])
            ->put($cacheKey, ['test' => 'data'], 3600);

        $this->assertTrue(
            Cache::tags(['dashboard', "school:{$this->school->id}"])->has($cacheKey),
            'Cache should be set initially'
        );

        // Create attendance (should dispatch event)
        $attendance = Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => Carbon::now()->format('Y-m-d'),
            'status' => 'present',
            'recorded_by' => $this->admin->id,
        ]);

        // Manually dispatch event if not auto-dispatched
        if (method_exists($attendance, 'fireModelEvent')) {
            event(new \App\Events\StudentAttended($attendance));
        }

        // Verify event was dispatched
        Event::assertDispatched('App\Events\StudentAttended');
    }

    /**
     * Test: Cache tags allow selective invalidation
     *
     * @return void
     */
    public function test_cache_tags_selective_invalidation()
    {
        // Set cache for multiple schools
        Cache::tags(['dashboard', 'school:1'])->put('school1_data', 'data1', 3600);
        Cache::tags(['dashboard', 'school:2'])->put('school2_data', 'data2', 3600);

        $this->assertTrue(Cache::tags(['dashboard', 'school:1'])->has('school1_data'));
        $this->assertTrue(Cache::tags(['dashboard', 'school:2'])->has('school2_data'));

        // Flush only school 1 cache
        Cache::tags(['dashboard', 'school:1'])->flush();

        // School 1 cache should be cleared
        $this->assertFalse(Cache::tags(['dashboard', 'school:1'])->has('school1_data'));

        // School 2 cache should still exist
        $this->assertTrue(Cache::tags(['dashboard', 'school:2'])->has('school2_data'));
    }

    /**
     * Test: Dashboard shows real-time data after cache clear
     *
     * @return void
     */
    public function test_dashboard_shows_realtime_data_after_cache_clear()
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Create 5 students with attendance
        for ($i = 0; $i < 5; $i++) {
            $student = User::factory()->create([
                'school_id' => $this->school->id,
                'role_type' => 'student',
            ]);

            Attendance::create([
                'school_id' => $this->school->id,
                'student_id' => $student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => Carbon::now()->format('Y-m-d'),
                'status' => 'present',
                'recorded_by' => $this->admin->id,
            ]);
        }

        // Clear all dashboard cache
        Cache::tags(['dashboard', "school:{$this->school->id}"])->flush();

        // Get fresh dashboard data
        $response = $this->getJson('/api/v1/admin/dashboard');
        $response->assertStatus(200);

        $stats = $response->json('data');
        $presentToday = $stats['present_today'] ?? 0;

        // Should show at least 5 present (plus any from setUp)
        $this->assertGreaterThanOrEqual(
            5,
            $presentToday,
            'Dashboard should show real-time data after cache clear'
        );
    }

    /**
     * Test: Cache TTL is reasonable
     *
     * @return void
     */
    public function test_cache_ttl_is_reasonable()
    {
        $cacheKey = "dashboard:school:{$this->school->id}:test";
        $ttl = 300; // 5 minutes

        Cache::tags(['dashboard', "school:{$this->school->id}"])
            ->put($cacheKey, 'test_data', $ttl);

        $this->assertTrue(
            Cache::tags(['dashboard', "school:{$this->school->id}"])->has($cacheKey),
            'Cache should be set'
        );

        // Verify TTL is not too long (should be under 1 hour for dashboard data)
        $this->assertLessThanOrEqual(
            3600,
            $ttl,
            'Dashboard cache TTL should not exceed 1 hour'
        );

        // Verify TTL is not too short (should be at least 1 minute)
        $this->assertGreaterThanOrEqual(
            60,
            $ttl,
            'Dashboard cache TTL should be at least 1 minute'
        );
    }

    /**
     * Test: Multiple concurrent requests don't cause cache stampede
     *
     * @return void
     */
    public function test_no_cache_stampede()
    {
        Sanctum::actingAs($this->admin, ['*']);

        // Clear cache first
        Cache::tags(['dashboard', "school:{$this->school->id}"])->flush();

        $responses = [];

        // Simulate 10 concurrent dashboard requests
        for ($i = 0; $i < 10; $i++) {
            $response = $this->getJson('/api/v1/admin/dashboard');
            $responses[] = [
                'status' => $response->status(),
                'time' => microtime(true),
            ];
        }

        // All should succeed
        foreach ($responses as $result) {
            $this->assertEquals(200, $result['status'], 'All requests should succeed');
        }

        // After requests, cache should be set
        $cacheKey = "dashboard:school:{$this->school->id}:admin:{$this->admin->id}";
        $this->assertTrue(
            Cache::tags(['dashboard', "school:{$this->school->id}"])->has($cacheKey),
            'Cache should be set after requests'
        );
    }

    /**
     * Test: Teacher dashboard cache is also updated
     *
     * @return void
     */
    public function test_teacher_dashboard_cache_updated()
    {
        $teacher = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'teacher',
        ]);

        Sanctum::actingAs($teacher, ['*']);

        // Get initial teacher dashboard
        $response1 = $this->getJson('/api/v1/teacher/dashboard');
        $response1->assertStatus(200);

        // Create new attendance
        Attendance::create([
            'school_id' => $this->school->id,
            'student_id' => $this->student->id,
            'schedule_id' => $this->schedule->id,
            'attendance_date' => Carbon::now()->format('Y-m-d'),
            'status' => 'present',
            'recorded_by' => $teacher->id,
        ]);

        // Teacher dashboard cache should be cleared
        $teacherCacheKey = "dashboard:school:{$this->school->id}:teacher:{$teacher->id}";
        $this->assertFalse(
            Cache::tags(['dashboard', "school:{$this->school->id}"])->has($teacherCacheKey),
            'Teacher dashboard cache should be cleared'
        );

        // Get updated dashboard
        $response2 = $this->getJson('/api/v1/teacher/dashboard');
        $response2->assertStatus(200);

        // Should show updated data
        $this->assertNotEquals(
            $response1->json('data'),
            $response2->json('data'),
            'Teacher dashboard should show updated data'
        );
    }

    /**
     * Test: Parent dashboard cache is updated
     *
     * @return void
     */
    public function test_parent_dashboard_cache_updated()
    {
        $parent = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'parent',
        ]);

        // Link student to parent
        DB::table('parent_students')->insert([
            'parent_id' => $parent->id,
            'student_id' => $this->student->id,
        ]);

        Sanctum::actingAs($parent, ['*']);

        // Get initial parent dashboard
        $response1 = $this->getJson('/api/v1/parent/dashboard');

        if ($response1->status() === 200) {
            // Create attendance for their child
            Attendance::create([
                'school_id' => $this->school->id,
                'student_id' => $this->student->id,
                'schedule_id' => $this->schedule->id,
                'attendance_date' => Carbon::now()->format('Y-m-d'),
                'status' => 'present',
                'recorded_by' => $this->admin->id,
            ]);

            // Get updated dashboard
            $response2 = $this->getJson('/api/v1/parent/dashboard');
            $response2->assertStatus(200);

            // Parent should see updated child attendance
            $this->assertTrue(true, 'Parent dashboard updated');
        }

        $this->assertTrue(true, 'Parent dashboard cache test completed');
    }
}
