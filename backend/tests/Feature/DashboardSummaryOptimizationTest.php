<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;

use App\Models\AttendanceDailyClassSummary;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dashboard Summary Optimization Test
 * 
 * Verifies that dashboard queries use the summary table correctly
 * and return accurate data with improved performance.
 * 
 * Task: 15.5 Update dashboard queries to use summary table
 */
class DashboardSummaryOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected School $school;
    protected User $admin;
    protected Classroom $class;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test school
        $this->school = School::factory()->create([
            'name' => 'Test School',
            'is_active' => true,
        ]);

        // Create admin user
        $this->admin = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'school_admin',
            'is_active' => true,
        ]);

        // Create test class
        $this->class = Classroom::factory()->create([
            'school_id' => $this->school->id,
            'name' => 'Class 10A',
            'grade' => 10,
        ]);
    }


    #[Test]
    public function dashboard_summary_uses_summary_table()
    {
        $today = Carbon::today()->toDateString();

        // Create summary data
        AttendanceDailyClassSummary::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'attendance_date' => $today,
            'total_students' => 30,
            'present_count' => 25,
            'late_count' => 3,
            'absent_count' => 2,
            'sick_count' => 0,
            'permit_count' => 0,
            'excused_count' => 0,
            'alpha_count' => 0,
            'last_updated_at' => now(),
        ]);

        // Call dashboard endpoint
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/school-admin/dashboard/summary');

        $response->assertOk();
        $response->assertJsonStructure([
            'total_students',
            'total_teachers',
            'classes_active_today',
            'attendance_today' => ['present', 'late', 'absent'],
            'attendance_rate',
            'students_not_checked_in',
            'attendance_trend',
        ]);

        // Verify data accuracy
        $data = $response->json();
        $this->assertEquals(25, $data['attendance_today']['present']);
        $this->assertEquals(3, $data['attendance_today']['late']);
        $this->assertEquals(2, $data['attendance_today']['absent']);
    }


    #[Test]
    public function live_attendance_uses_summary_table()
    {
        $today = Carbon::today()->toDateString();

        // Create summary data
        AttendanceDailyClassSummary::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'attendance_date' => $today,
            'total_students' => 30,
            'present_count' => 20,
            'late_count' => 5,
            'absent_count' => 5,
            'sick_count' => 0,
            'permit_count' => 0,
            'excused_count' => 0,
            'alpha_count' => 0,
            'last_updated_at' => now(),
        ]);

        // Call live attendance endpoint
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/school-admin/dashboard/live-attendance');

        $response->assertOk();
        $response->assertJsonStructure([
            'date',
            'classes' => [
                '*' => [
                    'class_id',
                    'class_name',
                    'grade',
                    'total_students',
                    'present',
                    'late',
                    'not_checked_in',
                ],
            ],
        ]);

        // Verify data accuracy
        $data = $response->json();
        $this->assertEquals($today, $data['date']);
        $this->assertCount(1, $data['classes']);
        $this->assertEquals(20, $data['classes'][0]['present']);
        $this->assertEquals(5, $data['classes'][0]['late']);
    }


    #[Test]
    public function monthly_stats_uses_summary_table()
    {
        $month = Carbon::now()->format('Y-m');
        $today = Carbon::today()->toDateString();

        // Create summary data for multiple days
        for ($i = 0; $i < 5; $i++) {
            $date = Carbon::today()->subDays($i)->toDateString();
            AttendanceDailyClassSummary::create([
                'school_id' => $this->school->id,
                'class_id' => $this->class->id,
                'attendance_date' => $date,
                'total_students' => 30,
                'present_count' => 25,
                'late_count' => 3,
                'absent_count' => 2,
                'sick_count' => 0,
                'permit_count' => 0,
                'excused_count' => 0,
                'alpha_count' => 0,
                'last_updated_at' => now(),
            ]);
        }

        // Call monthly stats endpoint
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/school-admin/dashboard/monthly-stats?month={$month}");

        $response->assertOk();
        $response->assertJsonStructure([
            'class_attendance_rates',
            'top_students',
            'worst_students',
            'trend',
        ]);

        // Verify trend data exists
        $data = $response->json();
        $this->assertNotEmpty($data['trend']);
    }


    #[Test]
    public function principal_attendance_overview_uses_summary_table()
    {
        $today = Carbon::today()->toDateString();

        // Create principal user
        $principal = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'principal',
            'is_active' => true,
        ]);

        // Create summary data
        AttendanceDailyClassSummary::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'attendance_date' => $today,
            'total_students' => 30,
            'present_count' => 25,
            'late_count' => 3,
            'absent_count' => 2,
            'sick_count' => 0,
            'permit_count' => 0,
            'excused_count' => 0,
            'alpha_count' => 0,
            'last_updated_at' => now(),
        ]);

        // Call principal dashboard endpoint
        $response = $this->actingAs($principal, 'sanctum')
            ->getJson('/api/v1/principal/dashboard/attendance-overview');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'school_summary',
                'monthly_trend',
                'class_breakdown',
            ],
        ]);

        // Verify data accuracy
        $data = $response->json('data');
        $this->assertEquals(25, $data['school_summary']['present_today']);
        $this->assertEquals(3, $data['school_summary']['late_today']);
        $this->assertEquals(2, $data['school_summary']['absent_today']);
    }


    #[Test]
    public function principal_class_performance_uses_summary_table()
    {
        $today = Carbon::today()->toDateString();

        // Create principal user
        $principal = User::factory()->create([
            'school_id' => $this->school->id,
            'role_type' => 'principal',
            'is_active' => true,
        ]);

        // Create summary data for 30 days
        for ($i = 0; $i < 30; $i++) {
            $date = Carbon::today()->subDays($i)->toDateString();
            AttendanceDailyClassSummary::create([
                'school_id' => $this->school->id,
                'class_id' => $this->class->id,
                'attendance_date' => $date,
                'total_students' => 30,
                'present_count' => 25,
                'late_count' => 3,
                'absent_count' => 2,
                'sick_count' => 0,
                'permit_count' => 0,
                'excused_count' => 0,
                'alpha_count' => 0,
                'last_updated_at' => now(),
            ]);
        }

        // Call class performance endpoint
        $response = $this->actingAs($principal, 'sanctum')
            ->getJson('/api/v1/principal/dashboard/class-performance');

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'data' => [
                'performance_ranking',
                'grade_comparison',
            ],
        ]);

        // Verify performance ranking exists
        $data = $response->json('data');
        $this->assertNotEmpty($data['performance_ranking']);
    }


    #[Test]
    public function summary_table_provides_accurate_attendance_rate()
    {
        $today = Carbon::today()->toDateString();

        // Create summary with known values
        $summary = AttendanceDailyClassSummary::create([
            'school_id' => $this->school->id,
            'class_id' => $this->class->id,
            'attendance_date' => $today,
            'total_students' => 100,
            'present_count' => 80,
            'late_count' => 10,
            'absent_count' => 10,
            'sick_count' => 0,
            'permit_count' => 0,
            'excused_count' => 0,
            'alpha_count' => 0,
            'last_updated_at' => now(),
        ]);

        // Verify attendance rate calculation
        $this->assertEquals(90, $summary->attended_students); // 80 + 10
        $this->assertEquals(90.0, $summary->attendance_rate); // (90/100) * 100
    }
}
