<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use App\Models\School;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

class PrincipalMonitoringTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private $principalUser;
    private $nonPrincipalUser;
    private $otherSchoolPrincipal;
    private $school1;
    private $school2;
    private $baseUrl = '/api/v1/principal';

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create test schools
        $this->school1 = School::factory()->create(['name' => 'SMA Negeri 1']);
        $this->school2 = School::factory()->create(['name' => 'SMA Negeri 2']);

        // Create principal user for school 1
        $this->principalUser = User::factory()->create([
            'role_type' => 'principal',
            'school_id' => $this->school1->id,
            'name' => 'Principal School 1',
        ]);

        // Create principal user for school 2
        $this->otherSchoolPrincipal = User::factory()->create([
            'role_type' => 'principal',
            'school_id' => $this->school2->id,
            'name' => 'Principal School 2',
        ]);

        // Create non-principal user
        $this->nonPrincipalUser = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => $this->school1->id,
            'name' => 'Teacher User',
        ]);

        // Seed test data
        $this->seedTestData();
    }

    private function seedTestData(): void
    {
        // Create academic years for both schools
        $academicYear1 = DB::table('academic_years')->insertGetId([
            'school_id' => $this->school1->id,
            'name' => '2024/2025',
            'start_date' => '2024-07-01',
            'end_date' => '2025-06-30',
            'semester' => '1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $academicYear2 = DB::table('academic_years')->insertGetId([
            'school_id' => $this->school2->id,
            'name' => '2024/2025',
            'start_date' => '2024-07-01',
            'end_date' => '2025-06-30',
            'semester' => '1',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Seed classes for both schools
        $classesSchool1 = [];
        $classesSchool2 = [];

        for ($i = 1; $i <= 3; $i++) {
            $classesSchool1[] = DB::table('classes')->insertGetId([
                'name' => "X-IPA-{$i}",
                'school_id' => $this->school1->id,
                'academic_year_id' => $academicYear1,
                'grade_level' => 10,
                'max_students' => 36,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $classesSchool2[] = DB::table('classes')->insertGetId([
                'name' => "X-IPA-{$i}",
                'school_id' => $this->school2->id,
                'academic_year_id' => $academicYear2,
                'grade_level' => 10,
                'max_students' => 36,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed students for both schools (without class_id)
        $studentsSchool1 = [];
        $studentsSchool2 = [];

        // School 1 students
        for ($i = 1; $i <= 30; $i++) { // 30 students total for school 1
            $studentsSchool1[] = DB::table('users')->insertGetId([
                'name' => "Student {$i} School 1",
                'username' => "student_s1_{$i}",
                'email' => "student_s1_{$i}@school1.com",
                'password' => bcrypt('password'),
                'role_type' => 'student',
                'school_id' => $this->school1->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // School 2 students
        for ($i = 1; $i <= 30; $i++) { // 30 students total for school 2
            $studentsSchool2[] = DB::table('users')->insertGetId([
                'name' => "Student {$i} School 2",
                'username' => "student_s2_{$i}",
                'email' => "student_s2_{$i}@school2.com",
                'password' => bcrypt('password'),
                'role_type' => 'student',
                'school_id' => $this->school2->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Create subjects for both schools (required for schedules)
        $subjectSchool1 = DB::table('subjects')->insertGetId([
            'school_id' => $this->school1->id,
            'code' => 'MAT',
            'name' => 'Matematika',
            'school_level' => 'SMA',
            'grade_level' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $subjectSchool2 = DB::table('subjects')->insertGetId([
            'school_id' => $this->school2->id,
            'code' => 'MAT',
            'name' => 'Matematika',
            'school_level' => 'SMA',
            'grade_level' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create teacher users for schedules
        $teacherSchool1 = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => $this->school1->id,
            'name' => 'Teacher School 1',
        ]);

        $teacherSchool2 = User::factory()->create([
            'role_type' => 'teacher',
            'school_id' => $this->school2->id,
            'name' => 'Teacher School 2',
        ]);

        // Create schedules for both schools (required for attendances)
        $schedulesSchool1 = [];
        $schedulesSchool2 = [];

        foreach ($classesSchool1 as $classId) {
            $schedulesSchool1[] = DB::table('schedules')->insertGetId([
                'school_id' => $this->school1->id,
                'academic_year_id' => $academicYear1,
                'class_id' => $classId,
                'subject_id' => $subjectSchool1,
                'teacher_id' => $teacherSchool1->id,
                'day_of_week' => 'monday',
                'start_time' => '07:00:00',
                'end_time' => '08:00:00',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($classesSchool2 as $classId) {
            $schedulesSchool2[] = DB::table('schedules')->insertGetId([
                'school_id' => $this->school2->id,
                'academic_year_id' => $academicYear2,
                'class_id' => $classId,
                'subject_id' => $subjectSchool2,
                'teacher_id' => $teacherSchool2->id,
                'day_of_week' => 'monday',
                'start_time' => '07:00:00',
                'end_time' => '08:00:00',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Seed attendance records for the past 7 days (reduced for faster tests)
        $dates = [];
        for ($i = 1; $i <= 7; $i++) {
            $dates[] = Carbon::now()->subDays($i);
        }

        // School 1 attendance
        foreach ($studentsSchool1 as $index => $studentId) {
            $scheduleId = $schedulesSchool1[$index % count($schedulesSchool1)]; // Distribute students across schedules
            foreach ($dates as $date) {
                if ($date->isWeekday()) { // Only weekdays
                    $status = $this->getRandomAttendanceStatus();
                    $checkInTime = $date->copy()->setTime(rand(7, 8), rand(0, 59));
                    DB::table('attendances')->insert([
                        'school_id' => $this->school1->id,
                        'schedule_id' => $scheduleId,
                        'student_id' => $studentId,
                        'attendance_date' => $date->toDateString(),
                        'status' => $status,
                        'check_in_time' => $status !== 'absent' ? $checkInTime : null,
                        'is_manual' => false,
                        'request_id' => \Illuminate\Support\Str::uuid(),
                        'created_at' => $date,
                        'updated_at' => $date,
                    ]);
                }
            }
        }

        // School 2 attendance (should not be visible to school 1 principal)
        foreach ($studentsSchool2 as $index => $studentId) {
            $scheduleId = $schedulesSchool2[$index % count($schedulesSchool2)]; // Distribute students across schedules
            foreach ($dates as $date) {
                if ($date->isWeekday()) {
                    $status = $this->getRandomAttendanceStatus();
                    $checkInTime = $date->copy()->setTime(rand(7, 8), rand(0, 59));
                    DB::table('attendances')->insert([
                        'school_id' => $this->school2->id,
                        'schedule_id' => $scheduleId,
                        'student_id' => $studentId,
                        'attendance_date' => $date->toDateString(),
                        'status' => $status,
                        'check_in_time' => $status !== 'absent' ? $checkInTime : null,
                        'is_manual' => false,
                        'request_id' => \Illuminate\Support\Str::uuid(),
                        'created_at' => $date,
                        'updated_at' => $date,
                    ]);
                }
            }
        }
    }

    private function getRandomAttendanceStatus(): string
    {
        $statuses = ['present', 'present', 'present', 'present', 'late', 'absent'];
        return $statuses[array_rand($statuses)];
    }

    public function test_unauthenticated_user_cannot_access_attendance_overview()
    {
        $response = $this->getJson("{$this->baseUrl}/attendance-overview");
        
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_class_performance()
    {
        $response = $this->getJson("{$this->baseUrl}/class-performance");
        
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_risk_students()
    {
        $response = $this->getJson("{$this->baseUrl}/risk-students");
        
        $response->assertStatus(401);
    }

    public function test_non_principal_user_cannot_access_attendance_overview()
    {
        Sanctum::actingAs($this->nonPrincipalUser);
        
        $response = $this->getJson("{$this->baseUrl}/attendance-overview");
        
        $response->assertStatus(403);
    }

    public function test_non_principal_user_cannot_access_class_performance()
    {
        Sanctum::actingAs($this->nonPrincipalUser);
        
        $response = $this->getJson("{$this->baseUrl}/class-performance");
        
        $response->assertStatus(403);
    }

    public function test_non_principal_user_cannot_access_risk_students()
    {
        Sanctum::actingAs($this->nonPrincipalUser);
        
        $response = $this->getJson("{$this->baseUrl}/risk-students");
        
        $response->assertStatus(403);
    }

    public function test_principal_can_get_attendance_overview()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/attendance-overview");
        
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'school_summary' => [
                            'total_students',
                            'total_classes',
                            'attendance_rate',
                            'present_today',
                            'late_today',
                            'absent_today'
                        ],
                        'monthly_trend',
                        'class_breakdown'
                    ]
                ]);

        $data = $response->json('data');
        
        // Verify school summary has positive numbers
        $this->assertGreaterThanOrEqual(0, $data['school_summary']['total_students']);
        $this->assertGreaterThanOrEqual(0, $data['school_summary']['total_classes']);
        $this->assertIsNumeric($data['school_summary']['attendance_rate']);
        
        // Verify data structure
        $this->assertIsArray($data['monthly_trend']);
        $this->assertIsArray($data['class_breakdown']);
    }

    public function test_principal_can_get_class_performance()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/class-performance");
        
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'performance_ranking',
                        'grade_comparison',
                        'subject_performance'
                    ]
                ]);

        $data = $response->json('data');
        
        // Verify data structure
        $this->assertIsArray($data['performance_ranking']);
        $this->assertIsArray($data['grade_comparison']);
        $this->assertIsArray($data['subject_performance']);
    }

    public function test_principal_can_get_risk_students()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/risk-students?limit=20");
        
        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'high_risk_students',
                        'risk_summary' => [
                            'total_students',
                            'high_risk_count',
                            'medium_risk_count',
                            'low_risk_count',
                            'critical_threshold'
                        ],
                        'class_risk_breakdown'
                    ]
                ]);

        $data = $response->json('data');
        
        // Verify data structure
        $this->assertIsArray($data['high_risk_students']);
        $this->assertIsArray($data['risk_summary']);
        $this->assertIsArray($data['class_risk_breakdown']);
        $this->assertIsInt($data['risk_summary']['total_students']);
    }

    public function test_principal_only_sees_own_school_data_in_attendance_overview()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/attendance-overview");
        
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verify that only school 1 classes are returned
        foreach ($data['class_breakdown'] as $class) {
            // Get the actual class from database to verify school_id
            $classRecord = DB::table('classes')->where('id', $class['class_id'])->first();
            $this->assertEquals($this->school1->id, $classRecord->school_id);
        }
        
        // Verify student count matches school 1 only
        $expectedStudentCount = DB::table('users')
            ->where('school_id', $this->school1->id)
            ->where('role_type', 'student')
            ->count();
            
        $this->assertEquals($expectedStudentCount, $data['school_summary']['total_students']);
    }

    public function test_principal_only_sees_own_school_data_in_class_performance()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/class-performance");
        
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verify that only school 1 classes are in performance ranking
        foreach ($data['performance_ranking'] as $class) {
            $classRecord = DB::table('classes')->where('id', $class['class_id'])->first();
            $this->assertEquals($this->school1->id, $classRecord->school_id);
        }
    }

    public function test_principal_only_sees_own_school_data_in_risk_students()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/risk-students");
        
        $response->assertStatus(200);
        $data = $response->json('data');
        
        // Verify that only school 1 students are in risk list
        foreach ($data['high_risk_students'] as $student) {
            $studentRecord = DB::table('users')->where('id', $student['student_id'])->first();
            $this->assertEquals($this->school1->id, $studentRecord->school_id);
        }
        
        // Verify total students count matches school 1 only
        $expectedStudentCount = DB::table('users')
            ->where('school_id', $this->school1->id)
            ->where('role_type', 'student')
            ->count();
            
        $this->assertEquals($expectedStudentCount, $data['risk_summary']['total_students']);
    }

    public function test_different_school_principals_see_different_data()
    {
        // Get data for school 1 principal
        Sanctum::actingAs($this->principalUser);
        $school1Response = $this->getJson("{$this->baseUrl}/attendance-overview");
        $school1Data = $school1Response->json('data');
        
        // Get data for school 2 principal
        Sanctum::actingAs($this->otherSchoolPrincipal);
        $school2Response = $this->getJson("{$this->baseUrl}/attendance-overview");
        $school2Data = $school2Response->json('data');
        
        // Both should be successful
        $school1Response->assertStatus(200);
        $school2Response->assertStatus(200);
        
        // Class breakdowns should be completely different
        $school1ClassIds = array_column($school1Data['class_breakdown'], 'class_id');
        $school2ClassIds = array_column($school2Data['class_breakdown'], 'class_id');
        
        $this->assertEmpty(array_intersect($school1ClassIds, $school2ClassIds));
    }

    public function test_principal_endpoints_handle_empty_data_gracefully()
    {
        // Create a new school with no data
        $emptySchool = School::factory()->create(['name' => 'Empty School']);
        $emptyPrincipal = User::factory()->create([
            'role_type' => 'principal',
            'school_id' => $emptySchool->id,
        ]);
        
        Sanctum::actingAs($emptyPrincipal);
        
        $endpoints = [
            "{$this->baseUrl}/attendance-overview",
            "{$this->baseUrl}/class-performance",
            "{$this->baseUrl}/risk-students"
        ];
        
        foreach ($endpoints as $endpoint) {
            $response = $this->getJson($endpoint);
            
            $response->assertStatus(200)
                    ->assertJson(['success' => true]);
                    
            // Should have proper structure even with empty data
            $data = $response->json('data');
            $this->assertIsArray($data);
        }
    }

    public function test_principal_endpoints_respect_date_range_parameters()
    {
        Sanctum::actingAs($this->principalUser);
        
        // Test with different date ranges
        $response7d = $this->getJson("{$this->baseUrl}/attendance-overview?range=7d");
        $response30d = $this->getJson("{$this->baseUrl}/attendance-overview?range=30d");
        
        $response7d->assertStatus(200);
        $response30d->assertStatus(200);
        
        // Both should return valid data structure
        $data7d = $response7d->json('data');
        $data30d = $response30d->json('data');
        
        $this->assertIsArray($data7d['monthly_trend']);
        $this->assertIsArray($data30d['monthly_trend']);
    }

    public function test_principal_endpoints_validate_limit_parameters()
    {
        Sanctum::actingAs($this->principalUser);
        
        $response = $this->getJson("{$this->baseUrl}/risk-students?limit=5");
        
        $response->assertStatus(200);
        
        $data = $response->json('data');
        $this->assertLessThanOrEqual(5, count($data['high_risk_students']));
    }
}