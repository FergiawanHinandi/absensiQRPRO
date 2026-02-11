<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Models\School;
use App\Models\Attendance;
use App\Models\TeachingSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CrossSchoolIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test logic: Admin of School A cannot see attendance of School B.
     */
    public function test_admin_cannot_access_other_school_data()
    {
        // Setup School A
        $schoolA = School::create(['name' => 'School A']);
        $adminA = User::factory()->create(['school_id' => $schoolA->id, 'role_type' => 'school_admin']);

        // Setup School B with Data
        $schoolB = School::create(['name' => 'School B']);
        $studentB = User::factory()->create(['school_id' => $schoolB->id, 'role_type' => 'student']);
        $attendanceB = Attendance::factory()->create([
            'student_id' => $studentB->id,
            'school_id' => $schoolB->id
        ]);

        // Attempt Access via API (IDOR attempt)
        $response = $this->actingAs($adminA)
                         ->getJson("/api/v1/attendance/{$attendanceB->id}");

        // Expect 403 Forbidden OR 404 Not Found (if scoped correctly)
        // 404 is better for security (enumeration protection)
        $this->assertTrue(in_array($response->status(), [403, 404]));
    }

    /**
     * Test logic: Teacher cannot check-in student to another school's session via QR
     */
    public function test_teacher_cannot_scan_for_other_school_session()
    {
        // 1. Setup Data
        $schoolA = School::create(['name' => 'School A']);
        $teacherA = User::factory()->create(['school_id' => $schoolA->id, 'role_type' => 'teacher']);
        
        $schoolB = School::create(['name' => 'School B']);
        $sessionB = TeachingSession::factory()->create(['school_id' => $schoolB->id]);
        
        // 2. Craft Payload for School B Session
        $payload = [
            'session_id' => $sessionB->id,
            'teacher_id' => 999, // Irrelevant if IDOR check fails
            'generated_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinute()->toIso8601String(),
            'signature' => 'dummy', // Assuming we bypass signature for this test logic or gen valid one
        ];
        
        // Fix signature to pass validation wrapper if any
        $payload['signature'] = hash_hmac('sha256', json_encode(array_diff_key($payload, ['signature' => ''])), config('app.key'));

        // 3. Attack
        $response = $this->actingAs($teacherA)
                         ->postJson('/api/v1/teacher/scan', ['qr_payload' => $payload]);

        // 4. Assert Failure
        // Should Fail because Session B belongs to School B
        $this->assertNotEquals(200, $response->status());
    }
}
