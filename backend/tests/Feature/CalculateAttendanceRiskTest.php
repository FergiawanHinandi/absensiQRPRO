<?php

namespace Tests\Feature;

use App\Jobs\CalculateAttendanceRisk;
use App\Models\Attendance;
use App\Models\School;
use App\Models\StudentAttendanceRisk;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculateAttendanceRiskTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_attendance_risk_job()
    {
        // 1. Setup Data
        $school = School::factory()->create();
        $teacher = User::factory()->create(['school_id' => $school->id, 'role_type' => 'teacher']);
        $class = \App\Models\ClassModel::factory()->create([
            'school_id' => $school->id,
            'homeroom_teacher_id' => $teacher->id,
        ]);
        $schedule = \App\Models\Schedule::factory()->create([
            'school_id' => $school->id,
            'class_id' => $class->id,
        ]);

        // Active Student 1: High Risk (3 Absences on Mondays)
        $studentHigh = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Active Student 2: Low Risk (Perfect Attendance)
        $studentLow = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => true,
        ]);

        // Inactive Student
        $studentInactive = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'student',
            'is_active' => false,
        ]);

        // Create Absences for High Risk Student (Last 30 days) - Specifically on Mondays to test risky_weekday
        // Let's pick a recent Monday within the last 30 days
        $lastMonday = Carbon::now();
        while ($lastMonday->dayOfWeek !== Carbon::MONDAY) {
            $lastMonday->subDay();
        }

        // Ensure we are within 30 days window for the 3 absences
        // If today is Monday, lastMonday is today.
        // We need 3 Mondays. today, -1 week, -2 weeks. All within 30 days.

        for ($i = 0; $i < 3; $i++) {
            Attendance::create([
                'school_id' => $school->id,
                'student_id' => $studentHigh->id,
                'class_id' => $class->id,
                'schedule_id' => $schedule->id,
                'status' => 'absent',
                // Create absences on 3 consecutive Mondays
                'attendance_date' => $lastMonday->copy()->subWeeks($i)->toDateString(),
                'method' => 'manual',
                'request_id' => "req_high_$i",
            ]);
        }

        // Create Present for Low Risk Student
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => $studentLow->id,
            'class_id' => $class->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
            'attendance_date' => Carbon::now()->subDay()->toDateString(),
            'check_in' => '07:00:00',
            'method' => 'qr',
            'request_id' => 'req_low_1',
        ]);

        // 2. Run Job
        (new CalculateAttendanceRisk)->handle();

        // 3. Assertions
        // High Risk Student
        $this->assertDatabaseHas('student_attendance_risk', [
            'student_id' => $studentHigh->id,
            'risk_level' => 'high', // Score 55 >= 50 -> High
        ]);

        $riskHigh = StudentAttendanceRisk::where('student_id', $studentHigh->id)->first();
        // Score: 3 absences * 10 = 30.
        // Penalty: 0% attendance (< 50%) = +20.
        // Risky Weekday Penalty: +5
        // Total = 55.
        $this->assertEquals(55, $riskHigh->risk_score);
        $this->assertEquals('Monday', $riskHigh->risky_weekday);

        // Low Risk Student
        $this->assertDatabaseHas('student_attendance_risk', [
            'student_id' => $studentLow->id,
            'risk_level' => 'low',
        ]);

        $riskLow = StudentAttendanceRisk::where('student_id', $studentLow->id)->first();
        $this->assertEquals(0, $riskLow->risk_score); // 0 score

        // Inactive Student - Should NOT have risk record
        $this->assertDatabaseMissing('student_attendance_risk', [
            'student_id' => $studentInactive->id,
        ]);
    }
}
