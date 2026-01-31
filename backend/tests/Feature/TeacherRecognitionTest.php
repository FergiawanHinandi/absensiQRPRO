<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\School;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TeacherRecognitionTest extends TestCase
{
    use RefreshDatabase;

    public function test_teacher_recognition_data()
    {
        // 1. Setup Data
        $school = School::factory()->create();
        $admin = User::factory()->create([
            'school_id' => $school->id,
            'role_type' => 'admin',
        ]);

        // Create Teachers
        $teacher1 = User::factory()->create(['school_id' => $school->id, 'role_type' => 'teacher', 'name' => 'Teacher 1']);
        $teacher2 = User::factory()->create(['school_id' => $school->id, 'role_type' => 'teacher', 'name' => 'Teacher 2']);

        // Create Classes
        $class1 = \App\Models\ClassModel::factory()->create([
            'school_id' => $school->id,
            'homeroom_teacher_id' => $teacher1->id,
            'name' => 'Class 1A',
        ]);
        $class2 = \App\Models\ClassModel::factory()->create([
            'school_id' => $school->id,
            'homeroom_teacher_id' => $teacher2->id,
            'name' => 'Class 1B',
        ]);

        // Create Schedule
        $schedule = \App\Models\Schedule::factory()->create([
            'school_id' => $school->id,
            'class_id' => $class1->id,
        ]);

        // Create Attendance Scan Request ID (dummy string or UUID)
        $requestId = 'req_' . uniqid();

        // Create Attendances (This Month)
        // Class 1: 100% attendance (1 record)
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => 1, // dummy
            'class_id' => $class1->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
            'attendance_date' => Carbon::now()->startOfMonth()->toDateString(),
            'check_in' => '07:00:00',
            'check_out' => '12:00:00',
            'method' => 'qr',
            'request_id' => $requestId,
        ]);

        // Class 2: 0% attendance (1 record absent)
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => 2, // dummy
            'class_id' => $class2->id,
            'schedule_id' => $schedule->id,
            'status' => 'absent',
            'attendance_date' => Carbon::now()->startOfMonth()->toDateString(),
            'check_in' => null,
            'check_out' => null,
            'method' => 'manual',
            'request_id' => $requestId . '_2',
        ]);

        // Create Attendances (Last Month) for Improvement
        // Class 1: 50% attendance last month (1 present, 1 absent)
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => 1,
            'class_id' => $class1->id,
            'schedule_id' => $schedule->id,
            'status' => 'present',
            'attendance_date' => $lastMonth->copy()->toDateString(),
            'check_in' => '07:00:00',
            'check_out' => '12:00:00',
            'method' => 'qr',
            'request_id' => $requestId . '_3',
        ]);
        Attendance::create([
            'school_id' => $school->id,
            'student_id' => 1,
            'class_id' => $class1->id,
            'schedule_id' => $schedule->id,
            'status' => 'absent',
            'attendance_date' => $lastMonth->copy()->addDay()->toDateString(),
            'check_in' => null,
            'check_out' => null,
            'method' => 'manual',
            'request_id' => $requestId . '_4',
        ]);

        // Class 1 Improvement: This Month (100%) - Last Month (50%) = +50%

        // 2. Act
        Sanctum::actingAs($admin, ['report:view']);
        $response = $this->getJson('/api/v1/reports/teacher-recognition');

        // 3. Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'best_attendance_teacher' => [
                        'teacher_id',
                        'teacher_name',
                        'class_id',
                        'attendance_rate',
                    ],
                    'most_improved_classes' => [
                        '*' => [
                            'class_id',
                            'class_name',
                            'improvement',
                        ]
                    ]
                ]
            ]);

        $data = $response->json('data');
        
        // Assert Best Teacher is Teacher 1
        $this->assertEquals($teacher1->id, $data['best_attendance_teacher']['teacher_id']);
        $this->assertEquals(100, $data['best_attendance_teacher']['attendance_rate']);

        // Assert Class 1 is in most improved
        $class1Stats = collect($data['most_improved_classes'])->firstWhere('class_id', $class1->id);
        $this->assertNotNull($class1Stats);
        $this->assertEquals(50, $class1Stats['improvement']);
    }
}
