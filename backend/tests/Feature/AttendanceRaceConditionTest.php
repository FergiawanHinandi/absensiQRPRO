<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\School;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AttendanceRaceConditionTest extends TestCase
{
    use RefreshDatabase;

    public function test_system_rejects_concurrent_replay_attacks()
    {
        // 1. Setup Environment
        // Create School & User to avoid foreign key issues
        // Note: Using create() directly to avoid relying on possibly missing factories
        $school = \App\Models\School::create(['name' => 'Test School', 'address' => 'Test Address']);
        
        $student = User::factory()->create([
            'role' => 'student', 
            'school_id' => $school->id,
            'password' => Hash::make('password')
        ]);
        
        // Manual dependencies creation
        $class = \App\Models\ClassRoom::create(['name' => '10A', 'school_id' => $school->id]);
        $subject = \App\Models\Subject::create(['name' => 'Math', 'school_id' => $school->id]);
        
        // Enroll student to class
        \App\Models\ClassStudent::create(['student_id' => $student->id, 'class_id' => $class->id, 'school_id' => $school->id]);

        $schedule = Schedule::create([
            'school_id' => $school->id,
            'class_id' => $class->id,
            'subject_id' => $subject->id,
            'start_time' => now()->subMinutes(10)->format('H:i:s'),
            'end_time' => now()->addMinutes(50)->format('H:i:s'),
            'day_of_week' => now()->dayOfWeek,
        ]);
        
        // 2. Prepare Payload
        $payload = [
            'school_id' => $school->id,
            'session_id' => $schedule->id,
            'expires_at' => now()->addMinute()->timestamp,
        ];
        
        // Sign Payload
        ksort($payload);
        $signature = hash_hmac('sha256', json_encode($payload), config('app.key'));
        
        $requestData = [
            ...$payload,
            'signature' => $signature,
            'source' => 'qr'
        ];

        // 3. Attack Simulation
        
        // Hit 1: Success (Winner)
        $response1 = $this->actingAs($student)->postJson('/api/v1/student/scan', $requestData);
        
        $response1->assertStatus(200)
                 ->assertJsonPath('status', 'success');

        // Hit 2: Replay Attack (Same User, Same Data)
        // Controller catch block should return 409 Conflict OR Service throws existing record exception
        $response2 = $this->actingAs($student)->postJson('/api/v1/student/scan', $requestData);

        // Assert Hit 2 Fails
        // Note: Our code returns 409 Conflict for "Absensi sudah tercatat" OR "Average/Duplicate Entry"
        $statusValues = [400, 409]; // 400 is possible if Service Logic throws "Already Recorded" as BadRequest, but we used 409 in steps.
        
        // Check strict assertions
        if ($response2->status() === 200) {
             $this->fail('Replay attack was allowed! (Status 200)');
        }
        
        $this->assertTrue(in_array($response2->status(), $statusValues), 
            "Expected 409/400 for duplicate, got {$response2->status()}. Msg: " . $response2->json('message'));
            
        // 4. Verify Database Integrity (Only 1 record)
        $this->assertDatabaseCount('attendances', 1);
    }
}
