<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\School;
use App\Models\Attendance;
use App\Models\ClassModel;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Laravel\Sanctum\Sanctum;

class StudentSemesterReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_semester_summary_calculation()
    {
        // 1. Setup Environment (Mock Date: Aug 15, 2025 -> Semester Ganjil)
        Carbon::setTestNow(Carbon::create(2025, 8, 15));

        $school = School::factory()->create();
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'admin', // school_admin
        ]);
        
        $teacher = User::factory()->create(['school_id' => $school->id, 'role_type' => 'teacher']);
        
        // Use ClassModel instead of Classes
        $class = ClassModel::factory()->create([
            'school_id' => $school->id,
            'homeroom_teacher_id' => $teacher->id
        ]);

        $student = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'total_points' => 600 // Gold Tier (>500)
        ]);

        $schedule = Schedule::factory()->create([
            'school_id' => $school->id,
            'class_id' => $class->id
        ]);

        // 2. Create Attendances (In Semester: July - Dec)
        // Present: Aug 1
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
            'attendance_date' => '2025-08-01',
            'check_in' => '07:00:00',
            'method' => 'qr',
            'request_id' => 'req_1'
        ]);

        // Late: Aug 2
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'schedule_id' => $schedule->id,
            'status' => 'late',
            'attendance_date' => '2025-08-02',
            'check_in' => '07:45:00',
            'method' => 'qr',
            'request_id' => 'req_2'
        ]);

        // Absent: Aug 3
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'schedule_id' => $schedule->id,
            'status' => 'absent',
            'attendance_date' => '2025-08-03',
            'method' => 'manual',
            'request_id' => 'req_3'
        ]);

        // 3. Create Attendance (Out of Semester: Jan 2025)
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => $student->id,
            'class_id' => $class->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
            'attendance_date' => '2025-01-15', // Previous semester
            'check_in' => '07:00:00',
            'method' => 'qr',
            'request_id' => 'req_old'
        ]);

        // 4. Authenticate as Admin
        Sanctum::actingAs($admin, ['report:view']);

        // 5. Call Endpoint
        $response = $this->getJson("/api/v1/reports/student-semester-summary/{$student->id}");

        // 6. Verify
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'student_id' => $student->id,
                    'reward_tier' => 'Gold', // 600 points
                    'total_school_days' => 3, // 1 present + 1 late + 1 absent (Jan ignored)
                    'late_count' => 1,
                    'absence_count' => 1,
                    'present_percentage' => 66.7, // (2 / 3) * 100
                ]
            ]);
            
        // Check date range in response
        $data = $response->json('data');
        $this->assertEquals('2025-07-01', $data['period']['start']);
        $this->assertEquals('2025-12-31', $data['period']['end']);
    }
}
